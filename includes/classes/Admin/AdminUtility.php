<?php

namespace bKash\PGW\Admin;

class AdminUtility {
	private static $instance;

	static function getInstance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function loadTable( $title, $tbl_name, $columns = array(), $filters = array(), $actions = array() ) {
		global $wpdb;
		$table_name = $wpdb->prefix . $tbl_name;
		$pagenum    = isset( $_GET['pagenum'] ) ? absint( sanitize_text_field( $_GET['pagenum'] ) ) : 1;

		$searchFilters = [];
		if ( count( $filters ) > 0 ) {
			foreach ( $filters as $key => $filter ) {
				$normalized = self::normalizeFilter( $key, $filter );
				$input      = isset( $_GET[ $normalized['key'] ] ) ? sanitize_text_field( wp_unslash( $_GET[ $normalized['key'] ] ) ) : '';
				if ( $input === '' ) {
					continue;
				}

				$column = str_replace( '`', '', sanitize_key( $normalized['key'] ) );
				if ( $column === '' ) {
					continue;
				}

				if ( $normalized['type'] === 'select' ) {
					$allowed = array_map( 'strval', array_keys( $normalized['options'] ) );
					if ( ! in_array( $input, $allowed, true ) ) {
						continue;
					}
					$searchFilters[] = $wpdb->prepare( 'AND `' . $column . '` = %s', $input );
				} else {
					$searchFilters[] = $wpdb->prepare( 'AND `' . $column . '` LIKE %s', '%' . $wpdb->esc_like( $input ) . '%' );
				}
			}
		}

		$limit           = BKASH_FW_TABLE_LIMIT;
		$offset          = ( $pagenum - 1 ) * $limit;
		$selectFrom      = "SELECT * from $table_name where ID > %d ";
		$selectCountFrom = "SELECT count(*) as total from $table_name where ID > %d ";
		$filterSql       = implode( "", $searchFilters );

		$prepareQuery = $wpdb->prepare( $selectFrom, 0 ) . $filterSql;
		$rows         = $wpdb->get_results( $prepareQuery . $wpdb->prepare( " ORDER BY id DESC limit %d, %d", $offset, $limit ) );
		$rowcount     = $wpdb->num_rows ?? 0;

		$total        = $wpdb->get_var(
			$wpdb->prepare( $selectCountFrom, 0 ) . $filterSql
		);
		$num_of_pages = ceil( $total / $limit );

		$page_links = paginate_links( array(
			'base'      => add_query_arg( 'pagenum', '%#%' ),
			'format'    => '',
			'prev_text' => __( '&laquo;', 'bkash-for-woocommerce' ),
			'next_text' => __( '&raquo;', 'bkash-for-woocommerce' ),
			'total'     => $num_of_pages,
			'current'   => $pagenum
		) );

		include_once "pages/table.php";
	}

	public static function normalizeFilter( $key, $filter ) {
		if ( is_array( $filter ) ) {
			$label       = isset( $filter['label'] ) ? (string) $filter['label'] : $key;
			$empty_label = isset( $filter['empty_label'] ) ? (string) $filter['empty_label'] : ( 'All ' . $label );
			$options     = ( isset( $filter['options'] ) && is_array( $filter['options'] ) ) ? $filter['options'] : array();

			return array(
				'key'         => $key,
				'label'       => $label,
				'type'        => isset( $filter['type'] ) ? (string) $filter['type'] : 'search',
				'empty_label' => $empty_label,
				'options'     => $options,
			);
		}

		return array(
			'key'         => $key,
			'label'       => (string) $filter,
			'type'        => 'search',
			'empty_label' => '',
			'options'     => array(),
		);
	}

	public static function get_bKash_options( $plugin_id, $key ) {
		$option_value = false;
		$options      = get_option( 'woocommerce_' . $plugin_id . '_settings' );

		if ( ! is_null( $options ) && isset( $options[ $key ] ) ) {
			if ( $options[ $key ] === 'yes' || $options[ $key ] === 'no' ) {
				$option_value = $options[ $key ] === 'yes';
			} else {
				$option_value = $options[ $key ];
			}
		}

		return $option_value;
	}

	public static function validate_response( $apiResp = array(), $specificField = array() ) {
		$feedback = array(
			'valid'    => false,
			'message'  => '',
			'response' => []
		);


		if ( isset( $apiResp['status_code'], $apiResp['response'] ) && $apiResp['status_code'] === 200 ) {
			$response = $apiResp['response'];
			if ( is_string( $response ) ) {
				$response = json_decode( $response, true );
			}

			if ( isset( $response['errorMessage'] ) ) {
				$feedback['message'] = $response['errorMessage'];
			} else if ( isset( $response['statusMessage'] ) && $response['statusMessage'] !== 'Successful' ) {
				$feedback['message'] = $response['statusMessage'];
			} else {
				if ( count( $specificField ) > 0 ) {
					if ( $response[ key( $specificField ) ] === $specificField[ key( $specificField ) ] ) {
						$feedback['valid'] = true;
					} else {
						$feedback['message'] = key( $specificField ) . " is not present or not matching with the value " . $specificField[ key( $specificField ) ];
					}
				} else {
					$feedback['valid'] = true;
				}

				$feedback['response'] = $response;
			}
		} else {
			$feedback['message'] = "Action cannot be performed at bKash server right now, try again";
		}

		return $feedback;
	}


	public static function redirect_to_page() {

		$page        = sanitize_text_field( $_GET["page"] ?? '' );
		$actual_link = strtok( "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]", '?' );

		wp_redirect( esc_url( $actual_link . "?page=" . $page ) );
	}

	public static function add_flash_notice( $notice = "", $type = "warning", $dismissible = true ) {
		$notices = get_option( "bKash_flash_notices", array() );

		$dismissible_text = ( $dismissible ) ? "is-dismissible" : "";

		$notices[] = array(
			"notice"      => $notice,
			"type"        => $type,
			"dismissible" => $dismissible_text
		);

		update_option( "bKash_flash_notices", $notices );
	}
}