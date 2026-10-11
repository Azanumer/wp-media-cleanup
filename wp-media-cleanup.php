<?php
/**
 * Plugin Name: WP Media Cleanup
 * Description: Find unattached media, duplicate files, oversized uploads and orphaned files on disk — then delete them in bulk and reclaim disk space.
 * Version: 1.0.0
 * Author: Azan Umer
 * License: MIT
 * Text Domain: wmc
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPMediaCleanup {

    const PAGE = 'wp-media-cleanup';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_wmc_delete', array( __CLASS__, 'handle_delete' ) );
    }

    public static function menu() {
        add_management_page(
            __( 'Media Cleanup', 'wmc' ),
            __( 'Media Cleanup', 'wmc' ),
            'manage_options',
            self::PAGE,
            array( __CLASS__, 'page' )
        );
    }

    /** Attachments scanned in pages so big libraries don't blow memory. */
    public static function scan_limit() {
        return (int) apply_filters( 'wmc_scan_limit', 500 );
    }

    /** "Large file" threshold in bytes (default 2 MB). */
    public static function large_file_bytes() {
        return (int) apply_filters( 'wmc_large_file_bytes', 2 * 1024 * 1024 );
    }

    /** Run all four checks. Returns array of sections => items. */
    public static function scan() {
        $upload_dir = wp_upload_dir();
        $basedir    = $upload_dir['basedir'];
        $results    = array(
            'unattached' => array(),
            'duplicates' => array(),
            'large'      => array(),
            'orphaned'   => array(),
        );

        // --- Library scan -------------------------------------------------
        $page     = 1;
        $by_name  = array(); // basename => [ids]
        $known    = array(); // relative path => attachment id (originals + files on disk)
        $thumbs   = array(); // generated sizes we must NOT flag as orphaned
        do {
            $ids = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => self::scan_limit(),
                'paged'          => $page,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ) );
            foreach ( $ids as $id ) {
                $file = get_attached_file( $id );
                if ( ! $file ) {
                    continue;
                }
                $rel            = ltrim( str_replace( $basedir, '', $file ), '/' );
                $known[ $rel ]  = $id;
                $by_name[ basename( $rel ) ][] = $id;

                if ( (int) get_post_field( 'post_parent', $id ) === 0 ) {
                    $results['unattached'][] = self::item( $id, $file );
                }
                if ( file_exists( $file ) && filesize( $file ) >= self::large_file_bytes() ) {
                    $results['large'][] = self::item( $id, $file );
                }
                // Remember generated thumbnails so the orphan check skips them.
                $meta = wp_get_attachment_metadata( $id );
                if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                    $dir = dirname( $rel );
                    foreach ( $meta['sizes'] as $size ) {
                        if ( ! empty( $size['file'] ) ) {
                            $thumbs[ $dir . '/' . $size['file'] ] = true;
                        }
                    }
                }
            }
            $page++;
        } while ( count( $ids ) === self::scan_limit() );

        // --- Duplicates ---------------------------------------------------
        foreach ( $by_name as $name => $ids ) {
            if ( count( $ids ) > 1 ) {
                foreach ( $ids as $id ) {
                    $results['duplicates'][] = self::item( $id, get_attached_file( $id ) );
                }
            }
        }

        // --- Orphaned files on disk ---------------------------------------
        if ( is_dir( $basedir ) ) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $it as $f ) {
                if ( ! $f->isFile() ) {
                    continue;
                }
                $rel = ltrim( str_replace( $basedir, '', $f->getPathname() ), '/' );
                if ( isset( $known[ $rel ] ) || isset( $thumbs[ $rel ] ) ) {
                    continue;
                }
                $results['orphaned'][] = array(
                    'id'    => 0,
                    'title' => $rel,
                    'path'  => $f->getPathname(),
                    'size'  => $f->getSize(),
                    'url'   => '',
                );
            }
        }

        return $results;
    }

    public static function item( $id, $file ) {
        return array(
            'id'    => $id,
            'title' => get_the_title( $id ),
            'path'  => $file,
            'size'  => file_exists( $file ) ? filesize( $file ) : 0,
            'url'   => wp_get_attachment_url( $id ),
        );
    }

    /* ---- Bulk delete ---- */

    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wmc' ), 403 );
        }
        check_admin_referer( 'wmc_delete' );

        $selected = isset( $_POST['wmc_items'] ) ? (array) $_POST['wmc_items'] : array();
        $deleted  = 0;
        $freed    = 0;

        foreach ( $selected as $key ) {
            // Library items arrive as "att:123", orphaned files as "file:" + base64 path.
            if ( strpos( $key, 'att:' ) === 0 ) {
                $id   = (int) substr( $key, 4 );
                $file = get_attached_file( $id );
                $size = $file && file_exists( $file ) ? filesize( $file ) : 0;
                if ( wp_delete_attachment( $id, true ) ) {
                    $deleted++;
                    $freed += $size;
                }
            } elseif ( strpos( $key, 'file:' ) === 0 ) {
                $path       = base64_decode( substr( $key, 5 ) );
                $upload_dir = wp_upload_dir();
                // Safety: only touch files inside the uploads directory.
                $real = realpath( $path );
                if ( $real && strpos( $real, realpath( $upload_dir['basedir'] ) ) === 0 && is_file( $real ) ) {
                    $size = filesize( $real );
                    if ( unlink( $real ) ) {
                        $deleted++;
                        $freed += $size;
                    }
                }
            }
        }

        add_settings_error(
            'wmc',
            'deleted',
            sprintf(
                /* translators: 1: count, 2: size */
                __( 'Deleted %1$d item(s), freed %2$s.', 'wmc' ),
                $deleted,
                size_format( $freed )
            ),
            'updated'
        );
        set_transient( 'settings_errors', get_settings_errors(), 30 );
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE ) );
        exit;
    }

    /* ---- Admin page ---- */

    public static function page() {
        $results = self::scan();
        $total   = 0;
        $bytes   = 0;
        foreach ( $results as $items ) {
            $total += count( $items );
            foreach ( $items as $it ) {
                $bytes += (int) $it['size'];
            }
        }
        $sections = array(
            'unattached' => __( 'Unattached media', 'wmc' ),
            'duplicates' => __( 'Duplicate filenames', 'wmc' ),
            'large'      => sprintf( __( 'Large files (≥ %s)', 'wmc' ), size_format( self::large_file_bytes() ) ),
            'orphaned'   => __( 'Orphaned files on disk', 'wmc' ),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Media Cleanup', 'wmc' ); ?></h1>
            <p><?php
                printf(
                    /* translators: 1: count, 2: size */
                    esc_html__( 'Found %1$d item(s) using %2$s. Tick what you want gone, then delete — nothing is removed until you confirm.', 'wmc' ),
                    (int) $total,
                    esc_html( size_format( $bytes ) )
                );
            ?></p>
            <?php settings_errors( 'wmc' ); ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wmc_delete" />
                <?php wp_nonce_field( 'wmc_delete' ); ?>
                <?php foreach ( $sections as $key => $label ) :
                    $items = $results[ $key ];
                    if ( empty( $items ) ) {
                        continue;
                    }
                ?>
                <h2><?php echo esc_html( $label ); ?> (<?php echo count( $items ); ?>)</h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" class="wmc-check-all" /></td>
                            <th><?php esc_html_e( 'File', 'wmc' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'wmc' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $items as $it ) :
                            $value = $it['id'] > 0 ? 'att:' . $it['id'] : 'file:' . base64_encode( $it['path'] );
                        ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="wmc_items[]" value="<?php echo esc_attr( $value ); ?>" /></th>
                            <td>
                                <?php if ( $it['url'] ) : ?>
                                    <a href="<?php echo esc_url( $it['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $it['title'] ); ?>
                                <?php endif; ?>
                                <br><small><code><?php echo esc_html( $it['path'] ); ?></code></small>
                            </td>
                            <td><?php echo esc_html( size_format( $it['size'] ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>
                <?php if ( $total > 0 ) : ?>
                    <p><?php submit_button( __( 'Delete selected', 'wmc' ), 'delete', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Permanently delete the selected items?', 'wmc' ) ) . "');" ) ); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e( 'Nothing to clean — your media library is tidy.', 'wmc' ); ?></p>
                <?php endif; ?>
            </form>
        </div>
        <script>
        document.querySelectorAll('.wmc-check-all').forEach(function (master) {
            master.addEventListener('change', function () {
                var table = master.closest('table');
                table.querySelectorAll('tbody input[type=checkbox]').forEach(function (box) {
                    box.checked = master.checked;
                });
            });
        });
        </script>
        <?php
    }
}

WPMediaCleanup::init();
