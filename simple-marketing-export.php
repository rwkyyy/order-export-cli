<?php
/*
Plugin Name: Simple Export of Orders for Marketing Campaigns
Description: Exports customer phone and email from completed WooCommerce orders via WP-CLI.
Version: 1.0
Author: Eduard V. Doloc
Author URI: https://uprise.ro
*/

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class Simple_Marketing_Export_Command {

		/**
		 * Export phone and email from completed WooCommerce orders.
		 *
		 * ## EXAMPLES
		 *
		 *     wp simple-marketing export
		 *
		 * @when after_wp_load
		 */
		public function export() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				WP_CLI::error( 'WooCommerce is not active.' );
			}

			global $wpdb;

			$batch_size = 5000;
			$offset     = 0;

			// Get total count
			$total_orders_count = $wpdb->get_var("
			    SELECT COUNT(*)
			    FROM {$wpdb->prefix}wc_order_addresses a
			    INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id
			    WHERE a.address_type = 'billing'
			    AND o.status = 'wc-completed'
			");

			if ( empty( $total_orders_count ) ) {
			    WP_CLI::warning( 'No completed orders found.' );
			    return;
			}

			$upload_dir = plugin_dir_path( __FILE__ ) . 'exports/';
			if ( ! file_exists( $upload_dir ) ) {
				mkdir( $upload_dir, 0755, true );
			}

			$filename = $upload_dir . 'marketing-export-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
			$file     = fopen( $filename, 'w' );
			fputcsv( $file, [ 'phone' ] );

			$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting orders', $total_orders_count );

			do {
			    $results = $wpdb->get_results( $wpdb->prepare("
			        SELECT a.phone
			        FROM {$wpdb->prefix}wc_order_addresses a
			        INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = a.order_id
			        WHERE a.address_type = 'billing'
			        AND o.status = 'wc-completed'
			        LIMIT %d OFFSET %d
			    ", $batch_size, $offset ) );

			    if ( empty( $results ) ) {
			        break;
			    }

			    foreach ( $results as $row ) {
			        fputcsv( $file, [ $row->phone ] );
			        $progress->tick();
			    }

			    WP_CLI::log( "Processed offset $offset" );
			    $offset += $batch_size;

			} while ( true );

			$progress->finish();
			fclose( $file );
			WP_CLI::success( "Exported to $filename" );
		}
	}

	WP_CLI::add_command( 'simple-marketing', 'Simple_Marketing_Export_Command' );
}