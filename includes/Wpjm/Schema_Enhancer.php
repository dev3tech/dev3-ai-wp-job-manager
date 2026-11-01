<?php
/**
 * Better JobPosting JSON-LD. Hooks WPJM's own `wpjm_get_job_listing_structured_data` filter, so
 * there is still exactly one JSON-LD block, and fixes what WPJM leaves out or gets wrong for Google
 * for Jobs: numeric salary ranges, remote jobs without a location, employment types without term
 * meta, plain-string addresses, experience, skills and industry.
 *
 * @package Dev3\AiWpJobManager
 */

namespace Dev3\AiWpJobManager\Wpjm;

use Dev3\AiWpJobManager\Settings;
use Dev3\AiWpJobManager\Text\Salary;

defined( 'ABSPATH' ) || exit;

/**
 * Static service.
 */
class Schema_Enhancer {

	const MONTHS = array(
		'mid'       => 24,
		'senior'    => 60,
		'lead'      => 96,
		'executive' => 120,
	);

	const EMPLOYMENT = array(
		'full-time'  => 'FULL_TIME',
		'part-time'  => 'PART_TIME',
		'temporary'  => 'TEMPORARY',
		'freelance'  => 'CONTRACTOR',
		'contract'   => 'CONTRACTOR',
		'internship' => 'INTERN',
		'volunteer'  => 'VOLUNTEER',
		'per-diem'   => 'PER_DIEM',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wpjm_get_job_listing_structured_data', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * Filter callback.
	 *
	 * @param array|bool $data Structured data.
	 * @param \WP_Post   $post Post.
	 * @return array|bool
	 */
	public static function filter( $data, $post ) {
		if ( ! is_array( $data ) || ! Settings::get( 'schema_enhance', true ) ) {
			return $data;
		}
		$job = Wpjm::get( $post );
		return $job ? self::enhance( $data, $job ) : $data;
	}

	/**
	 * Improves a JobPosting array.
	 *
	 * @param array $data JSON-LD from WPJM.
	 * @param array $job  Listing.
	 * @return array
	 */
	public static function enhance( array $data, array $job ) {
		$data['@context'] = 'https://schema.org/';

		// Required basics.
		if ( empty( $data['title'] ) ) {
			$data['title'] = $job['title'];
		}
		if ( empty( $data['description'] ) ) {
			$data['description'] = wpautop( esc_html( $job['title'] ) );
		}
		if ( empty( $data['hiringOrganization']['name'] ) ) {
			$data['hiringOrganization'] = array_merge(
				isset( $data['hiringOrganization'] ) && is_array( $data['hiringOrganization'] ) ? $data['hiringOrganization'] : array(),
				array(
					'@type'  => 'Organization',
					'name'   => get_bloginfo( 'name' ),
					'sameAs' => home_url( '/' ),
				)
			);
		}
		$data['identifier'] = array(
			'@type' => 'PropertyValue',
			'name'  => $data['hiringOrganization']['name'],
			'value' => (string) $job['id'],
		);

		// Salary: WPJM puts the free-text string into "value", which Google rejects.
		$salary = Salary::parse( $job['salary'], $job['salary_currency'], $job['salary_unit'] );
		if ( $salary['ok'] ) {
			$value = array(
				'@type'    => 'QuantitativeValue',
				'unitText' => $salary['unit'],
			);
			if ( null !== $salary['min'] && null !== $salary['max'] && $salary['min'] !== $salary['max'] ) {
				$value['minValue'] = $salary['min'];
				$value['maxValue'] = $salary['max'];
			} else {
				$value['value'] = null !== $salary['min'] ? $salary['min'] : $salary['max'];
			}
			$data['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $salary['currency'],
				'value'    => $value,
			);
		} else {
			unset( $data['baseSalary'] );
		}

		// Employment type: map job type slugs when the terms have no employment_type meta.
		if ( empty( $data['employmentType'] ) && $job['types'] ) {
			$types = array();
			foreach ( $job['types'] as $t ) {
				$meta = (string) get_term_meta( $t['id'], 'employment_type', true );
				if ( '' !== $meta ) {
					$types[] = $meta;
				} elseif ( isset( self::EMPLOYMENT[ $t['slug'] ] ) ) {
					$types[] = self::EMPLOYMENT[ $t['slug'] ];
				}
			}
			if ( $types ) {
				$data['employmentType'] = array_values( array_unique( $types ) );
			}
		}

		// Location.
		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $job['location'] ) ) ) );
		if ( isset( $data['jobLocation']['address'] ) && is_string( $data['jobLocation']['address'] ) ) {
			$data['jobLocation']['address'] = array_filter(
				array(
					'@type'           => 'PostalAddress',
					'addressLocality' => $parts ? $parts[0] : $data['jobLocation']['address'],
					'addressRegion'   => count( $parts ) > 2 ? $parts[1] : '',
					'addressCountry'  => count( $parts ) > 1 ? end( $parts ) : '',
				)
			);
		}
		if ( $job['remote'] ) {
			$data['jobLocationType'] = 'TELECOMMUTE';
			$country                 = (string) get_post_meta( $job['id'], 'geolocation_country_long', true );
			if ( '' === $country && count( $parts ) > 1 ) {
				$country = end( $parts );
			}
			if ( '' === $country ) {
				$country = (string) Settings::get( 'remote_region', '' );
			}
			if ( '' !== $country && empty( $data['applicantLocationRequirements'] ) ) {
				$data['applicantLocationRequirements'] = array(
					'@type' => 'Country',
					'name'  => $country,
				);
			}
		}

		// Experience, skills, industry.
		$level = '' !== $job['seniority'] ? $job['seniority'] : \Dev3\AiWpJobManager\Text\Title::seniority( $job['title'] );
		if ( isset( self::MONTHS[ $level ] ) ) {
			$data['experienceRequirements'] = array(
				'@type'              => 'OccupationalExperienceRequirements',
				'monthsOfExperience' => self::MONTHS[ $level ],
			);
		} elseif ( 'entry' === $level ) {
			$data['experienceRequirements'] = 'no requirements';
		}
		if ( $job['tags'] ) {
			$data['skills'] = implode( ', ', wp_list_pluck( $job['tags'], 'name' ) );
		}
		if ( $job['categories'] ) {
			$data['industry'] = $job['categories'][0]['name'];
		}

		/**
		 * Filters the improved JobPosting JSON-LD.
		 *
		 * @param array $data JSON-LD.
		 * @param array $job  Listing.
		 */
		return apply_filters( 'dev3jm_schema', $data, $job );
	}

	/**
	 * JSON-LD for a listing (WPJM's builder + our filter) or a best-effort build without WPJM.
	 *
	 * @param int $id Listing.
	 * @return array|null
	 */
	public static function for_listing( $id ) {
		$job = Wpjm::get( $id );
		if ( ! $job ) {
			return null;
		}
		if ( function_exists( 'wpjm_get_job_listing_structured_data' ) ) {
			$data = wpjm_get_job_listing_structured_data( $id );
			if ( is_array( $data ) ) {
				return $data; // Exactly what WPJM prints (with our filter when enabled).
			}
		}
		return self::enhance(
			array(
				'@type'       => 'JobPosting',
				'datePosted'  => mysql2date( 'c', $job['date'], false ),
				'title'       => $job['title'],
				'description' => $job['content'],
			),
			$job
		);
	}

	/**
	 * Google for Jobs checks.
	 *
	 * @param array $data JSON-LD.
	 * @return array [ { key, level: required|recommended, ok, label } ]
	 */
	public static function validate( array $data ) {
		$has    = static function ( $key ) use ( $data ) {
			return ! empty( $data[ $key ] );
		};
		$remote = isset( $data['jobLocationType'] ) && 'TELECOMMUTE' === $data['jobLocationType'];
		$salary = isset( $data['baseSalary']['value'] ) && is_array( $data['baseSalary']['value'] ) && ( isset( $data['baseSalary']['value']['minValue'] ) || ( isset( $data['baseSalary']['value']['value'] ) && is_numeric( $data['baseSalary']['value']['value'] ) ) );
		$rows   = array(
			array( 'title', 'required', $has( 'title' ), __( 'Job title', 'dev3-ai-wp-job-manager' ) ),
			array( 'description', 'required', $has( 'description' ), __( 'Description', 'dev3-ai-wp-job-manager' ) ),
			array( 'datePosted', 'required', $has( 'datePosted' ), __( 'Date posted', 'dev3-ai-wp-job-manager' ) ),
			array( 'hiringOrganization', 'required', ! empty( $data['hiringOrganization']['name'] ), __( 'Hiring organisation', 'dev3-ai-wp-job-manager' ) ),
			array( 'jobLocation', 'required', $has( 'jobLocation' ) || ( $remote && $has( 'applicantLocationRequirements' ) ), __( 'Job location (or remote + applicant country)', 'dev3-ai-wp-job-manager' ) ),
			array( 'baseSalary', 'recommended', $salary, __( 'Salary as numbers', 'dev3-ai-wp-job-manager' ) ),
			array( 'employmentType', 'recommended', $has( 'employmentType' ), __( 'Employment type', 'dev3-ai-wp-job-manager' ) ),
			array( 'validThrough', 'recommended', $has( 'validThrough' ), __( 'Expiry date', 'dev3-ai-wp-job-manager' ) ),
			array( 'identifier', 'recommended', $has( 'identifier' ), __( 'Identifier', 'dev3-ai-wp-job-manager' ) ),
			array( 'directApply', 'recommended', isset( $data['directApply'] ), __( 'Direct apply flag', 'dev3-ai-wp-job-manager' ) ),
			array( 'experienceRequirements', 'optional', $has( 'experienceRequirements' ), __( 'Experience requirements', 'dev3-ai-wp-job-manager' ) ),
			array( 'skills', 'optional', $has( 'skills' ), __( 'Skills', 'dev3-ai-wp-job-manager' ) ),
		);
		$out    = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'key'   => $r[0],
				'level' => $r[1],
				'ok'    => (bool) $r[2],
				'label' => $r[3],
			);
		}
		return $out;
	}
}
