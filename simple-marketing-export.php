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

			$args = array(
				'status' => 'completed',
				'limit'  => - 1,
				'return' => 'ids',
			);

			$all_order_ids = wc_get_orders( $args );

			if ( empty( $all_order_ids ) ) {
				WP_CLI::warning( 'No completed orders found.' );

				return;
			}

			$upload_dir = plugin_dir_path( __FILE__ ) . 'exports/';
			if ( ! file_exists( $upload_dir ) ) {
				mkdir( $upload_dir, 0755, true );
			}

			$filename = $upload_dir . 'marketing-export-' . wp_generate_password( 16, false ) . '-' . date( 'Y-m-d-H-i-s' ) . '.csv';
			$file     = fopen( $filename, 'w' );
			fputcsv( $file, [ 'phone', 'email' ] );

			$batch_size    = 10000;
			$total_orders  = count( $all_order_ids );
			$total_batches = ceil( $total_orders / $batch_size );
			WP_CLI::log( "Starting batch export 1 out of $total_batches batches.\n" );

			$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting orders', $total_orders );

			for ( $batch = 0; $batch < $total_batches; $batch ++ ) {
				$offset          = $batch * $batch_size;
				$batch_order_ids = array_slice( $all_order_ids, $offset, $batch_size );

				foreach ( $batch_order_ids as $order_id ) {
					$order = wc_get_order( $order_id );
					if ( $order instanceof WC_Order_Refund ) {
						$progress->tick();
						continue;
					}

					if ( $order ) {
						$email = $order->get_billing_email();
						$phone = $order->get_billing_phone();
					} else {
						$email = get_post_meta( $order_id, '_billing_email', true );
						$phone = get_post_meta( $order_id, '_billing_phone', true );
					}

					fputcsv( $file, [ $phone, $email ] );
					$progress->tick();

					//avoid memory spikes for high order no - comment for speed!
					unset( $order );
				}

				sleep( 1 );
				WP_CLI::log( "Completed batch " . ( $batch + 1 ) . " of $total_batches." );
			}

			$progress->finish();
			fclose( $file );
			WP_CLI::success( "Exported to $filename" );
		}
	}

	WP_CLI::add_command( 'simple-marketing', 'Simple_Marketing_Export_Command' );
}
