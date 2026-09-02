<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transactional Checkout parsed-tree adapter for the verification block.
 */
final class WC_Blacklist_Manager_Checkout_Verification_Block_Tree {

	const BLOCK_NAME = 'wc-blacklist-manager/checkout-verification';

	private static $floor_steps = array(
		'woocommerce/checkout-contact-information-block',
		'woocommerce/checkout-shipping-address-block',
		'woocommerce/checkout-billing-address-block',
		'woocommerce/checkout-shipping-methods-block',
		'woocommerce/checkout-payment-block',
	);

	private static $current_steps = array(
		'woocommerce/checkout-contact-information-block',
		'woocommerce/checkout-shipping-method-block',
		'woocommerce/checkout-pickup-options-block',
		'woocommerce/checkout-shipping-address-block',
		'woocommerce/checkout-billing-address-block',
		'woocommerce/checkout-shipping-methods-block',
		'woocommerce/checkout-payment-block',
		'woocommerce/checkout-additional-information-block',
	);

	private static $pickup_steps = array(
		'woocommerce/checkout-contact-information-block',
		'woocommerce/checkout-shipping-method-block',
		'woocommerce/checkout-pickup-options-block',
		'woocommerce/checkout-shipping-address-block',
		'woocommerce/checkout-billing-address-block',
		'woocommerce/checkout-shipping-methods-block',
		'woocommerce/checkout-payment-block',
	);

	private static $terminal_blocks = array(
		'woocommerce/checkout-order-note-block',
		'woocommerce/checkout-terms-block',
		'woocommerce/checkout-actions-block',
	);

	private static $prefix_blocks = array(
		'woocommerce/checkout-express-payment-block',
	);

	public static function transform( $tree ) {
		if ( ! is_array( $tree ) || 1 !== self::count_named( $tree, 'woocommerce/checkout' ) ) {
			return $tree;
		}

		$copy = $tree;
		if ( ! self::transform_checkout( $copy ) ) {
			return $tree;
		}

		return $copy;
	}

	private static function transform_checkout( &$node ) {
		if ( ! is_array( $node ) ) {
			return false;
		}

		if ( 'woocommerce/checkout' === self::name( $node ) ) {
			return self::transform_checkout_node( $node );
		}

		foreach ( self::children( $node ) as $index => $child ) {
			if ( self::count_named( $child, 'woocommerce/checkout' ) > 0 ) {
				if ( ! self::valid_markers( $node ) || ! self::transform_checkout( $node['innerBlocks'][ $index ] ) ) {
					return false;
				}
				return true;
			}
		}

		return false;
	}

	private static function transform_checkout_node( &$checkout ) {
		if ( ! self::valid_markers_recursive( $checkout ) ) {
			return false;
		}

		$fields_indexes = array();
		foreach ( self::children( $checkout ) as $index => $child ) {
			if ( 'woocommerce/checkout-fields-block' === self::name( $child ) ) {
				$fields_indexes[] = $index;
			}
		}
		if ( 1 !== count( $fields_indexes ) ) {
			return false;
		}

		$fields_index = $fields_indexes[0];
		$fields       = $checkout['innerBlocks'][ $fields_index ];
		$classified   = self::classify_fields( $fields );
		if ( ! $classified ) {
			return false;
		}

		self::remove_named_recursive( $checkout, self::BLOCK_NAME );
		foreach ( self::children( $checkout ) as $index => $child ) {
			if ( 'woocommerce/checkout-fields-block' === self::name( $child ) ) {
				$fields_index = $index;
				break;
			}
		}
		$fields = $checkout['innerBlocks'][ $fields_index ];

		$insert_at = self::insertion_index( $fields, $classified['steps'] );
		if ( false === $insert_at ) {
			return false;
		}

		$attributes = array(
			'placement' => 'tree',
			'profile'   => $classified['profile'],
		);

		$block = array(
			'blockName'    => self::BLOCK_NAME,
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);

		self::insert_child( $fields, $insert_at, $block );
		$checkout['innerBlocks'][ $fields_index ] = $fields;
		return true;
	}

	private static function classify_fields( $fields ) {
		$names = array();
		$known_steps = array_merge( self::$floor_steps, self::$pickup_steps, self::$current_steps );
		foreach ( self::children( $fields ) as $child ) {
			$name = self::name( $child );
			if ( self::BLOCK_NAME === $name ) {
				continue;
			}
			if ( ! in_array( $name, array_merge( $known_steps, self::$prefix_blocks, self::$terminal_blocks ), true ) ) {
				return false;
			}
			$names[] = $name;
		}

		if (
			1 !== count( array_keys( $names, 'woocommerce/checkout-express-payment-block', true ) )
			|| 1 !== count( array_keys( $names, 'woocommerce/checkout-actions-block', true ) )
			|| count( array_keys( $names, 'woocommerce/checkout-order-note-block', true ) ) > 1
			|| count( array_keys( $names, 'woocommerce/checkout-terms-block', true ) ) > 1
		) {
			return false;
		}

		$encountered_steps = array_values(
			array_filter(
				$names,
				function ( $name ) use ( $known_steps ) {
					return in_array( $name, $known_steps, true );
				}
			)
		);
		$profiles = array(
			'floor'   => self::$floor_steps,
			'pickup'  => self::$pickup_steps,
			'current' => self::$current_steps,
		);
		$profile = '';
		$profile_steps = array();
		foreach ( $profiles as $candidate_profile => $candidate_steps ) {
			if ( $encountered_steps === $candidate_steps ) {
				$profile       = $candidate_profile;
				$profile_steps = $candidate_steps;
				break;
			}
		}
		if ( '' === $profile ) {
			return false;
		}

		return array( 'profile' => $profile, 'steps' => $profile_steps );
	}

	private static function insertion_index( $fields, $profile_steps ) {
		$children       = self::children( $fields );
		$first_step     = false;
		$last_step      = -1;
		$express        = array();
		$order_note     = array();
		$terms          = array();
		$actions        = array();
		foreach ( $children as $index => $child ) {
			$name = self::name( $child );
			if ( self::BLOCK_NAME === $name ) {
				continue;
			}
			if ( in_array( $name, $profile_steps, true ) ) {
				if ( false === $first_step ) {
					$first_step = $index;
				}
				$last_step = $index;
			}
			if ( 'woocommerce/checkout-express-payment-block' === $name ) {
				$express[] = $index;
			}
			if ( 'woocommerce/checkout-order-note-block' === $name ) {
				$order_note[] = $index;
			}
			if ( 'woocommerce/checkout-terms-block' === $name ) {
				$terms[] = $index;
			}
			if ( 'woocommerce/checkout-actions-block' === $name ) {
				$actions[] = $index;
			}
		}

		if ( 0 > $last_step || count( $express ) > 1 || count( $order_note ) > 1 || count( $terms ) > 1 || count( $actions ) > 1 ) {
			return false;
		}
		if ( ! empty( $express ) && $express[0] >= $first_step ) {
			return false;
		}
		if ( ( ! empty( $terms ) && $terms[0] <= $last_step ) || ( ! empty( $actions ) && $actions[0] <= $last_step ) ) {
			return false;
		}
		if ( ! empty( $terms ) && ! empty( $actions ) && $terms[0] > $actions[0] ) {
			return false;
		}
		if ( ! empty( $order_note ) ) {
			if (
				$order_note[0] <= $last_step
				|| ( ! empty( $terms ) && $order_note[0] >= $terms[0] )
				|| ( ! empty( $actions ) && $order_note[0] >= $actions[0] )
			) {
				return false;
			}
			return $order_note[0] + 1;
		}
		return $last_step + 1;
	}

	private static function remove_named_recursive( &$node, $name ) {
		if ( empty( $node['innerBlocks'] ) ) {
			return;
		}
		for ( $index = count( $node['innerBlocks'] ) - 1; $index >= 0; $index-- ) {
			if ( $name === self::name( $node['innerBlocks'][ $index ] ) ) {
				self::remove_child( $node, $index );
			} else {
				self::remove_named_recursive( $node['innerBlocks'][ $index ], $name );
			}
		}
	}

	private static function marker_offset( $content, $child_index ) {
		$seen = 0;
		foreach ( $content as $offset => $fragment ) {
			if ( null === $fragment ) {
				if ( $seen === $child_index ) {
					return $offset;
				}
				$seen++;
			}
		}
		return count( $content );
	}

	private static function remove_child( &$node, $index ) {
		$offset = self::marker_offset( $node['innerContent'], $index );
		array_splice( $node['innerBlocks'], $index, 1 );
		array_splice( $node['innerContent'], $offset, 1 );
	}

	private static function insert_child( &$node, $index, $child ) {
		$offset = self::marker_offset( $node['innerContent'], $index );
		array_splice( $node['innerBlocks'], $index, 0, array( $child ) );
		array_splice( $node['innerContent'], $offset, 0, array( null ) );
	}

	private static function valid_markers_recursive( $node ) {
		if ( ! self::valid_markers( $node ) ) {
			return false;
		}
		foreach ( self::children( $node ) as $child ) {
			if ( ! self::valid_markers_recursive( $child ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_markers( $node ) {
		$children = self::children( $node );
		$content  = isset( $node['innerContent'] ) && is_array( $node['innerContent'] ) ? $node['innerContent'] : array();
		return count( $children ) === count( array_filter( $content, 'is_null' ) );
	}

	private static function count_named( $node, $name ) {
		$count = $name === self::name( $node ) ? 1 : 0;
		foreach ( self::children( $node ) as $child ) {
			$count += self::count_named( $child, $name );
		}
		return $count;
	}

	private static function children( $node ) {
		return isset( $node['innerBlocks'] ) && is_array( $node['innerBlocks'] ) ? $node['innerBlocks'] : array();
	}

	private static function name( $node ) {
		return is_array( $node ) && isset( $node['blockName'] ) ? (string) $node['blockName'] : '';
	}
}
