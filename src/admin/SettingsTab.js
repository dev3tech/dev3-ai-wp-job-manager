import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, FormGrid, Modal, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';

const KEYS = [ 'submit_writer', 'submit_inclusive', 'submit_suggest', 'salary_hint', 'auto_normalise', 'schema_enhance', 'remote_region', 'register_tags', 'quality_column', 'match_ai', 'writer_tone', 'default_currency', 'public_limit', 'bulk_chunk' ];
const DEFAULTS = {
	submit_writer: true,
	submit_inclusive: true,
	submit_suggest: true,
	salary_hint: true,
	auto_normalise: false,
	schema_enhance: true,
	remote_region: '',
	register_tags: true,
	quality_column: true,
	match_ai: true,
	writer_tone: 'professional',
	default_currency: 'USD',
	public_limit: 10,
	bulk_chunk: 3,
};

/**
 * Plugin settings.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const [ confirm, setConfirm ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );

	const persist = ( values, message ) => {
		setBusy( true );
		return save( values )
			.then( ( next ) => {
				setForm( next );
				toast.success( message );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};
	const submit = ( e ) => {
		e.preventDefault();
		const values = {};
		KEYS.forEach( ( k ) => {
			values[ k ] = form[ k ] === '' && typeof DEFAULTS[ k ] === 'number' ? DEFAULTS[ k ] : form[ k ];
		} );
		persist( values, __( 'Settings saved.', 'dev3-ai-wp-job-manager' ) );
	};

	const footer = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', 'dev3-ai-wp-job-manager' ) }
		</Button>
	);

	return (
		<form onSubmit={ submit }>
			<PageHeader
				title={ __( 'Settings', 'dev3-ai-wp-job-manager' ) }
				description={ __( 'Choose where the assistant appears and how listings are improved.', 'dev3-ai-wp-job-manager' ) }
				actions={
					<Button variant="ghost" icon="refresh" onClick={ () => setConfirm( true ) }>
						{ __( 'Reset to defaults', 'dev3-ai-wp-job-manager' ) }
					</Button>
				}
			/>
			<Modal
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				size="sm"
				title={ __( 'Reset settings?', 'dev3-ai-wp-job-manager' ) }
				description={ __( 'All options on this page go back to their defaults. Your API key, drafts and usage history are kept.', 'dev3-ai-wp-job-manager' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirm( false ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-wp-job-manager' ) }
						</Button>
						<Button variant="danger" onClick={ () => persist( DEFAULTS, __( 'Settings reset to defaults.', 'dev3-ai-wp-job-manager' ) ).then( () => setConfirm( false ) ) } loading={ busy }>
							{ __( 'Reset settings', 'dev3-ai-wp-job-manager' ) }
						</Button>
					</>
				}
			/>
			<Stack gap={ 16 }>
				<Card title={ __( 'Front-end submit form', 'dev3-ai-wp-job-manager' ) } description={ __( 'A writing assistant inside WP Job Manager’s [submit_job_form]. Visitors see neutral wording, no DEV3 branding.', 'dev3-ai-wp-job-manager' ) } footer={ footer }>
					<FormGrid>
						<Toggle label={ __( 'Description writer', 'dev3-ai-wp-job-manager' ) } checked={ !! form.submit_writer } onChange={ set( 'submit_writer' ) } help={ __( 'Employers turn notes into a structured job ad.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Inclusive wording check', 'dev3-ai-wp-job-manager' ) } checked={ !! form.submit_inclusive } onChange={ set( 'submit_inclusive' ) } help={ __( 'Flags exclusionary phrases with one-click fixes.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Job type & tag suggestions', 'dev3-ai-wp-job-manager' ) } checked={ !! form.submit_suggest } onChange={ set( 'submit_suggest' ) } help={ __( 'Pre-selects the job type and fills tags.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Live salary reading', 'dev3-ai-wp-job-manager' ) } checked={ !! form.salary_hint } onChange={ set( 'salary_hint' ) } help={ __( 'Shows “Reads as USD 50,000 – 70,000 per year” under the salary field.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Normalise salary on submit', 'dev3-ai-wp-job-manager' ) } checked={ !! form.auto_normalise } onChange={ set( 'auto_normalise' ) } help={ __( 'Rules only: rewrites clear salaries into WPJM’s salary, currency and unit fields.', 'dev3-ai-wp-job-manager' ) } />
						<NumberField label={ __( 'AI requests per visitor per minute', 'dev3-ai-wp-job-manager' ) } value={ form.public_limit } onChange={ set( 'public_limit' ) } min={ 1 } max={ 120 } help={ __( 'Rate limit for the public assistant endpoints.', 'dev3-ai-wp-job-manager' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Listings & schema', 'dev3-ai-wp-job-manager' ) } footer={ footer }>
					<FormGrid>
						<Toggle label={ __( 'Improve JobPosting schema', 'dev3-ai-wp-job-manager' ) } checked={ !! form.schema_enhance } onChange={ set( 'schema_enhance' ) } help={ __( 'Numeric salaries, remote jobs, experience, skills and industry in WPJM’s JSON-LD.', 'dev3-ai-wp-job-manager' ) } />
						<TextField label={ __( 'Remote applicant country', 'dev3-ai-wp-job-manager' ) } value={ form.remote_region } onChange={ set( 'remote_region' ) } help={ __( 'Used for applicantLocationRequirements when a remote listing has no location, e.g. "United States".', 'dev3-ai-wp-job-manager' ) } />
						<TextField label={ __( 'Default salary currency', 'dev3-ai-wp-job-manager' ) } value={ form.default_currency } onChange={ ( v ) => set( 'default_currency' )( v.toUpperCase().slice( 0, 3 ) ) } help={ __( 'Used when a salary has no currency and WPJM has no default currency.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Job tags taxonomy', 'dev3-ai-wp-job-manager' ) } checked={ !! form.register_tags } onChange={ set( 'register_tags' ) } help={ __( 'Adds job tags when the WPJM Job Tags add-on is not installed.', 'dev3-ai-wp-job-manager' ) } />
						<Toggle label={ __( 'Quality column in Job Listings', 'dev3-ai-wp-job-manager' ) } checked={ !! form.quality_column } onChange={ set( 'quality_column' ) } />
						<Select
							label={ __( 'Default writing tone', 'dev3-ai-wp-job-manager' ) }
							value={ form.writer_tone }
							onChange={ set( 'writer_tone' ) }
							options={ [
								{ value: 'professional', label: __( 'Professional', 'dev3-ai-wp-job-manager' ) },
								{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-wp-job-manager' ) },
								{ value: 'startup', label: __( 'Startup', 'dev3-ai-wp-job-manager' ) },
								{ value: 'formal', label: __( 'Formal', 'dev3-ai-wp-job-manager' ) },
							] }
						/>
					</FormGrid>
				</Card>
				<Card title={ __( 'Matching & bulk', 'dev3-ai-wp-job-manager' ) } footer={ footer }>
					<FormGrid>
						<Toggle label={ __( 'DEV3 AI match explanations', 'dev3-ai-wp-job-manager' ) } checked={ !! form.match_ai } onChange={ set( 'match_ai' ) } help={ __( 'Off: candidate scores use the transparent rules only.', 'dev3-ai-wp-job-manager' ) } />
						<NumberField label={ __( 'Listings per background step', 'dev3-ai-wp-job-manager' ) } value={ form.bulk_chunk } onChange={ set( 'bulk_chunk' ) } min={ 1 } max={ 20 } help={ __( 'Lower it on slow hosts.', 'dev3-ai-wp-job-manager' ) } />
					</FormGrid>
				</Card>
			</Stack>
		</form>
	);
}
