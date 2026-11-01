import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, ErrorNotice, Modal, Select, Stack, Textarea, ToastProvider, useToast } from '../core3/ui';
import { getContent, getField, getTitle, setContent, setField, setTitle } from './editor-bridge';
import { QualityPill, htmlToText, salaryText, textToHtml } from './shared';

/**
 * Applies a wording fix like the PHP Inclusive::apply (case-insensitive, keeps a leading capital).
 *
 * @param {string} html       HTML.
 * @param {string} phrase     Phrase.
 * @param {string} suggestion Replacement ('' removes).
 * @return {string} HTML.
 */
export function applyFix( html, phrase, suggestion ) {
	const re = new RegExp( phrase.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'gi' );
	return html
		.replace( re, ( m ) => {
			if ( ! suggestion ) {
				return '';
			}
			const first = m.charAt( 0 );
			return first !== first.toLowerCase() ? suggestion.charAt( 0 ).toUpperCase() + suggestion.slice( 1 ) : suggestion;
		} )
		.replace( / {2,}/g, ' ' );
}

/**
 * Edit-screen assistant.
 *
 * @param {Object} props        Props.
 * @param {number} props.postId Post id.
 * @param {string} props.appUrl Full assistant URL.
 * @return {Element} Box.
 */
function Assistant( { postId, appUrl } ) {
	const toast = useToast();
	const [ info, setInfo ] = useState( null );
	const [ notes, setNotes ] = useState( '' );
	const [ tone, setTone ] = useState( 'professional' );
	const [ busy, setBusy ] = useState( '' );
	const [ draft, setDraft ] = useState( null );
	const [ issues, setIssues ] = useState( null );
	const [ norm, setNorm ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		request( `listings/${ postId }` )
			.then( setInfo )
			.catch( () => setInfo( false ) );
	}, [ postId ] );

	const current = () => ( { id: postId, title: getTitle(), content: getContent(), salary: getField( '_job_salary' ), salary_currency: getField( '_job_salary_currency' ), salary_unit: getField( '_job_salary_unit' ), location: getField( '_job_location' ), company: getField( '_company_name' ) } );
	const call = ( key, path, data, then ) => {
		setBusy( key );
		setError( null );
		request( path, { method: 'POST', data } )
			.then( then )
			.catch( setError )
			.finally( () => setBusy( '' ) );
	};

	const write = () => call( 'write', 'ai/write', { ...current(), notes, tone }, setDraft );
	const check = () => call( 'check', 'ai/inclusive', current(), ( d ) => setIssues( d.issues ) );
	const normalise = () => call( 'norm', 'ai/normalise', current(), setNorm );

	const acceptDraft = ( values ) => {
		setTitle( values.title );
		setContent( values.content === htmlToText( draft.content ) ? draft.content : textToHtml( values.content ) );
		setDraft( null );
		toast.success( __( 'Inserted into the editor. Update the listing to save it.', 'dev3-ai-wp-job-manager' ) );
	};
	const fix = ( i ) => {
		setContent( applyFix( getContent(), i.phrase, i.suggestion ) );
		setIssues( issues.filter( ( x ) => x !== i ) );
	};
	const fillSalary = () => {
		const s = norm.salary;
		const done = [ setField( '_job_salary', s.label ), setField( '_job_salary_currency', s.currency ), setField( '_job_salary_unit', s.unit ) ];
		if ( ! done[ 0 ] ) {
			toast.warning( __( 'Turn on the salary field in Job Listings → Settings first.', 'dev3-ai-wp-job-manager' ) );
			return;
		}
		toast.success( __( 'Salary fields filled. Update the listing to save them.', 'dev3-ai-wp-job-manager' ) );
	};

	const q = info && info.quality;
	return (
		<Stack gap={ 12 }>
			{ q && (
				<div className="dev3jm-mb__score">
					<QualityPill score={ q.score } />
					<span>
						<strong>{ __( 'Quality', 'dev3-ai-wp-job-manager' ) }</strong>
						<small>{ q.issues.length ? sprintf( /* translators: %d: count */ __( '%d things to improve (as last saved)', 'dev3-ai-wp-job-manager' ), q.issues.length ) : __( 'Looks great', 'dev3-ai-wp-job-manager' ) }</small>
					</span>
				</div>
			) }
			<Textarea label={ __( 'Notes for DEV3 AI', 'dev3-ai-wp-job-manager' ) } value={ notes } onChange={ setNotes } rows={ 3 } placeholder={ __( 'Key duties, must-haves, perks…', 'dev3-ai-wp-job-manager' ) } />
			<Select
				label={ __( 'Tone', 'dev3-ai-wp-job-manager' ) }
				value={ tone }
				onChange={ setTone }
				options={ [
					{ value: 'professional', label: __( 'Professional', 'dev3-ai-wp-job-manager' ) },
					{ value: 'friendly', label: __( 'Friendly', 'dev3-ai-wp-job-manager' ) },
					{ value: 'startup', label: __( 'Startup', 'dev3-ai-wp-job-manager' ) },
					{ value: 'formal', label: __( 'Formal', 'dev3-ai-wp-job-manager' ) },
				] }
			/>
			<AiButton block loading={ busy === 'write' } onClick={ write }>
				{ __( 'Write with DEV3 AI', 'dev3-ai-wp-job-manager' ) }
			</AiButton>
			<div className="dev3jm-mb__row">
				<Button size="sm" onClick={ check } loading={ busy === 'check' }>
					{ __( 'Check wording', 'dev3-ai-wp-job-manager' ) }
				</Button>
				<Button size="sm" onClick={ normalise } loading={ busy === 'norm' }>
					{ __( 'Normalise salary & title', 'dev3-ai-wp-job-manager' ) }
				</Button>
			</div>
			<ErrorNotice error={ error } />
			{ issues && (
				<div className="dev3jm-mb__panel">
					{ issues.length === 0 ? (
						<p>{ __( 'No exclusionary wording found.', 'dev3-ai-wp-job-manager' ) }</p>
					) : (
						<ul className="dev3jm-mb__issues">
							{ issues.map( ( i ) => (
								<li key={ i.phrase }>
									<span>
										<strong>“{ i.phrase }”</strong> { i.suggestion ? `→ “${ i.suggestion }”` : __( '(remove)', 'dev3-ai-wp-job-manager' ) }
										<small>{ i.reason }</small>
									</span>
									<Button size="sm" variant="secondary" onClick={ () => fix( i ) }>
										{ __( 'Fix', 'dev3-ai-wp-job-manager' ) }
									</Button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
			{ norm && (
				<div className="dev3jm-mb__panel">
					{ norm.title !== getTitle() && (
						<div className="dev3jm-mb__sugg">
							<span>
								<small>{ __( 'Cleaner title', 'dev3-ai-wp-job-manager' ) }</small>
								<strong>{ norm.title }</strong>
							</span>
							<Button size="sm" variant="secondary" onClick={ () => setTitle( norm.title ) }>
								{ __( 'Use', 'dev3-ai-wp-job-manager' ) }
							</Button>
						</div>
					) }
					{ norm.salary ? (
						<div className="dev3jm-mb__sugg">
							<span>
								<small>{ __( 'Salary reads as', 'dev3-ai-wp-job-manager' ) }</small>
								<strong>{ salaryText( norm.salary ) }</strong>
							</span>
							<Button size="sm" variant="secondary" onClick={ fillSalary }>
								{ __( 'Fill fields', 'dev3-ai-wp-job-manager' ) }
							</Button>
						</div>
					) : (
						<p>{ norm.negotiable ? __( 'Salary has no amount (e.g. "competitive").', 'dev3-ai-wp-job-manager' ) : __( 'No salary to normalise.', 'dev3-ai-wp-job-manager' ) }</p>
					) }
					{ norm.source === 'ai' && <Badge tone="brand">DEV3 AI</Badge> }
				</div>
			) }
			<Button variant="link" href={ appUrl }>
				{ __( 'Open the full DEV3 AI assistant', 'dev3-ai-wp-job-manager' ) }
			</Button>
			<Modal open={ !! draft } onClose={ () => setDraft( null ) } size="lg" title={ __( 'DEV3 AI · Job description draft', 'dev3-ai-wp-job-manager' ) }>
				{ draft && (
					<AiDraftReview
						fields={ [
							{ key: 'title', label: __( 'Title', 'dev3-ai-wp-job-manager' ), maxLength: 160 },
							{ key: 'content', label: __( 'Description', 'dev3-ai-wp-job-manager' ), multiline: true },
						] }
						original={ { title: getTitle(), content: htmlToText( getContent() ) } }
						draft={ { title: draft.title, content: htmlToText( draft.content ) } }
						meta={ draft.meta }
						onAccept={ acceptDraft }
						onReject={ () => setDraft( null ) }
						onRegenerate={ write }
						regenerating={ busy === 'write' }
						acceptLabel={ __( 'Insert into editor', 'dev3-ai-wp-job-manager' ) }
					/>
				) }
			</Modal>
		</Stack>
	);
}

/**
 * Mountable root with the toast provider.
 *
 * @param {Object} props Props.
 * @return {Element} Root.
 */
export default function Metabox( props ) {
	return (
		<ToastProvider>
			<Assistant { ...props } />
		</ToastProvider>
	);
}
