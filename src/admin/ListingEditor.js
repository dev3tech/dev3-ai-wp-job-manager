import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import { AiButton, AiDraftReview, Badge, Button, Card, Checkbox, Columns, EmptyState, ErrorNotice, FormGrid, Icon, Inline, Notice, NumberField, PageHeader, Select, SkeletonCard, Stack, Tabs, TextField, Textarea, useToast } from '../core3/ui';
import { LEVEL_LABELS, QualityPill, StatusBadge, UNIT_LABELS, htmlToText, salaryText, textToHtml } from './shared';

const TONES = {
	professional: __( 'Professional', 'dev3-ai-wp-job-manager' ),
	friendly: __( 'Friendly', 'dev3-ai-wp-job-manager' ),
	startup: __( 'Startup', 'dev3-ai-wp-job-manager' ),
	formal: __( 'Formal', 'dev3-ai-wp-job-manager' ),
};

const sourceBadge = ( source ) =>
	source === 'ai' ? (
		<Badge tone="brand">{ __( 'DEV3 AI', 'dev3-ai-wp-job-manager' ) }</Badge>
	) : (
		<Badge>{ source === 'template' ? __( 'Template (AI off)', 'dev3-ai-wp-job-manager' ) : __( 'Rules (AI off)', 'dev3-ai-wp-job-manager' ) }</Badge>
	);

/**
 * Description writer.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.listing Listing.
 * @param {Function} props.apply   Apply fields.
 * @param {Function} props.onUsage Usage changed.
 * @return {Element} Panel.
 */
function WriterPanel( { listing, apply, onUsage } ) {
	const toast = useToast();
	const [ notes, setNotes ] = useState( '' );
	const [ tone, setTone ] = useState( config.tones ? config.tones[ 0 ] : 'professional' );
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ draft, setDraft ] = useState( null );
	const [ error, setError ] = useState( null );

	const run = () => {
		setBusy( true );
		setError( null );
		request( 'ai/write', { method: 'POST', data: { id: listing.id, notes, tone } } )
			.then( ( d ) => {
				setDraft( d );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const accept = ( values ) => {
		setSaving( true );
		const text = values.content;
		const content = text === htmlToText( draft.content ) ? draft.content : textToHtml( text );
		apply( { title: values.title, content, seniority: draft.seniority || undefined } )
			.then( () => {
				toast.success( __( 'Description saved to the listing.', 'dev3-ai-wp-job-manager' ) );
				setDraft( null );
			} )
			.finally( () => setSaving( false ) );
	};

	return (
		<Stack gap={ 16 }>
			<Card tone="ai" title={ __( 'Write the description with DEV3 AI', 'dev3-ai-wp-job-manager' ) } description={ __( 'Uses the title, company, location, salary and the current description. Add notes for anything new.', 'dev3-ai-wp-job-manager' ) }>
				<FormGrid>
					<Textarea label={ __( 'Notes for the writer (optional)', 'dev3-ai-wp-job-manager' ) } value={ notes } onChange={ setNotes } rows={ 4 } maxLength={ 3000 } placeholder={ __( 'e.g. Team of 6, React + TypeScript, 4-day week, visa support', 'dev3-ai-wp-job-manager' ) } />
					<Select label={ __( 'Tone', 'dev3-ai-wp-job-manager' ) } value={ tone } onChange={ setTone } options={ Object.keys( TONES ).map( ( k ) => ( { value: k, label: TONES[ k ] } ) ) } />
				</FormGrid>
				<div className="dev3jm-gap-sm" />
				<AiButton loading={ busy } onClick={ run }>
					{ __( 'Write with DEV3 AI', 'dev3-ai-wp-job-manager' ) }
				</AiButton>
				<ErrorNotice error={ error } onRetry={ run } />
			</Card>
			{ draft && (
				<>
					<Inline>
						{ sourceBadge( draft.source ) }
						{ draft.seniority && <Badge tone="info">{ LEVEL_LABELS[ draft.seniority ] }</Badge> }
						{ draft.skills && draft.skills.length > 0 && (
							<span className="dev3jm-muted">
								{ __( 'Skills found:', 'dev3-ai-wp-job-manager' ) } { draft.skills.join( ', ' ) }
							</span>
						) }
					</Inline>
					<AiDraftReview
						fields={ [
							{ key: 'title', label: __( 'Title', 'dev3-ai-wp-job-manager' ), maxLength: 160 },
							{ key: 'content', label: __( 'Description', 'dev3-ai-wp-job-manager' ), multiline: true },
						] }
						original={ { title: listing.title, content: htmlToText( listing.content ) } }
						draft={ { title: draft.title, content: htmlToText( draft.content ) } }
						meta={ draft.meta }
						onAccept={ accept }
						accepting={ saving }
						onReject={ () => setDraft( null ) }
						onRegenerate={ run }
						regenerating={ busy }
						acceptLabel={ __( 'Save to listing', 'dev3-ai-wp-job-manager' ) }
					/>
					<Card title={ __( 'Preview', 'dev3-ai-wp-job-manager' ) } description={ __( 'How the draft is formatted on your job board.', 'dev3-ai-wp-job-manager' ) }>
						{ /* Server output: every text node is escaped (esc_html) before it is wrapped in tags. */ }
						<div className="dev3jm-preview" dangerouslySetInnerHTML={ { __html: draft.content } } />
					</Card>
				</>
			) }
		</Stack>
	);
}

/**
 * Inclusive-language check.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.listing Listing.
 * @param {Function} props.apply   Apply fields.
 * @param {Function} props.onUsage Usage changed.
 * @return {Element} Panel.
 */
function InclusivePanel( { listing, apply, onUsage } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ res, setRes ] = useState( null );
	const [ picked, setPicked ] = useState( {} );
	const [ error, setError ] = useState( null );
	const run = ( useAi = true ) => {
		setBusy( true );
		setError( null );
		request( 'ai/inclusive', { method: 'POST', data: { id: listing.id, use_ai: useAi } } )
			.then( ( d ) => {
				setRes( d );
				const all = {};
				d.issues.forEach( ( i, n ) => {
					all[ n ] = true;
				} );
				setPicked( all );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const save = () => {
		const fixes = res.issues.filter( ( i, n ) => picked[ n ] ).map( ( i ) => ( { phrase: i.phrase, suggestion: i.suggestion } ) );
		setSaving( true );
		apply( { fixes } )
			.then( () => {
				toast.success( sprintf( /* translators: %d: count */ __( '%d fixes applied.', 'dev3-ai-wp-job-manager' ), fixes.length ) );
				setRes( null );
			} )
			.finally( () => setSaving( false ) );
	};
	const count = res ? res.issues.filter( ( i, n ) => picked[ n ] ).length : 0;
	return (
		<Card
			tone="ai"
			title={ __( 'Inclusive-language check', 'dev3-ai-wp-job-manager' ) }
			description={ __( 'Finds gender-coded, age-coded, ableist and other exclusionary wording. A built-in word list always runs; DEV3 AI adds context-aware findings.', 'dev3-ai-wp-job-manager' ) }
			footer={
				res &&
				res.issues.length > 0 && (
					<Button variant="primary" icon="check" onClick={ save } loading={ saving } disabled={ ! count }>
						{ sprintf( /* translators: %d: count */ __( 'Apply %d fixes', 'dev3-ai-wp-job-manager' ), count ) }
					</Button>
				)
			}
		>
			<Inline>
				<AiButton loading={ busy } onClick={ () => run( true ) }>
					{ __( 'Check with DEV3 AI', 'dev3-ai-wp-job-manager' ) }
				</AiButton>
				<Button onClick={ () => run( false ) } disabled={ busy }>
					{ __( 'Word list only', 'dev3-ai-wp-job-manager' ) }
				</Button>
			</Inline>
			<ErrorNotice error={ error } onRetry={ () => run( true ) } />
			{ res && res.issues.length === 0 && (
				<Notice tone="success" title={ __( 'No exclusionary wording found', 'dev3-ai-wp-job-manager' ) }>
					{ res.source === 'ai' ? __( 'Checked by the word list and DEV3 AI.', 'dev3-ai-wp-job-manager' ) : __( 'Checked by the word list.', 'dev3-ai-wp-job-manager' ) }
				</Notice>
			) }
			{ res && res.issues.length > 0 && (
				<ul className="dev3jm-issues">
					{ res.issues.map( ( i, n ) => (
						<li key={ `${ i.phrase }-${ n }` } className="dev3jm-issue">
							<Checkbox
								label={
									<>
										<span className="dev3jm-issue__phrase">“{ i.phrase }”</span>
										{ i.suggestion ? (
											<>
												{ ' → ' }
												<span className="dev3jm-issue__fix">“{ i.suggestion }”</span>
											</>
										) : (
											<span className="dev3jm-muted"> { __( '(remove)', 'dev3-ai-wp-job-manager' ) }</span>
										) }
									</>
								}
								help={ i.reason }
								checked={ !! picked[ n ] }
								onChange={ ( v ) => setPicked( { ...picked, [ n ]: v } ) }
							/>
							<Badge tone={ i.source === 'ai' ? 'brand' : 'neutral' }>{ i.source === 'ai' ? 'DEV3 AI' : __( 'Word list', 'dev3-ai-wp-job-manager' ) }</Badge>
						</li>
					) ) }
				</ul>
			) }
		</Card>
	);
}

/**
 * Salary + title normalisation.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.listing Listing.
 * @param {Function} props.apply   Apply.
 * @param {Function} props.onUsage Usage changed.
 * @return {Element} Panel.
 */
function NormalisePanel( { listing, apply, onUsage } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ res, setRes ] = useState( null );
	const [ form, setForm ] = useState( null );
	const [ error, setError ] = useState( null );
	const run = ( force = false ) => {
		setBusy( true );
		setError( null );
		request( 'ai/normalise', { method: 'POST', data: { id: listing.id, force } } )
			.then( ( d ) => {
				setRes( d );
				const s = d.salary || {};
				setForm( { title: d.title, seniority: d.seniority || '', min: s.min ?? '', max: s.max ?? '', currency: s.currency || config.currency || '', unit: s.unit || 'YEAR' } );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	const save = () => {
		const fields = { title: form.title, seniority: form.seniority };
		if ( form.min !== '' || form.max !== '' ) {
			fields.salary = { min: form.min === '' ? null : form.min, max: form.max === '' ? null : form.max, currency: form.currency, unit: form.unit };
		}
		setSaving( true );
		apply( fields )
			.then( () => {
				toast.success( __( 'Title and salary saved to WP Job Manager fields.', 'dev3-ai-wp-job-manager' ) );
				setRes( null );
			} )
			.finally( () => setSaving( false ) );
	};
	const draftSalary = form ? { min: form.min === '' ? null : Number( form.min ), max: form.max === '' ? null : Number( form.max ), currency: form.currency, unit: form.unit } : null;
	return (
		<Card
			tone="ai"
			title={ __( 'Salary & title normalisation', 'dev3-ai-wp-job-manager' ) }
			description={ __( 'Turns free-text pay ("$50k-70k/yr") into numbers Google for Jobs accepts, and cleans hype words, emojis and abbreviations out of titles.', 'dev3-ai-wp-job-manager' ) }
			footer={
				form && (
					<>
						<Button variant="ghost" onClick={ () => setRes( null ) }>
							{ __( 'Discard', 'dev3-ai-wp-job-manager' ) }
						</Button>
						<Button variant="primary" icon="check" onClick={ save } loading={ saving }>
							{ __( 'Save to listing', 'dev3-ai-wp-job-manager' ) }
						</Button>
					</>
				)
			}
		>
			<Columns min={ 220 }>
				<div className="dev3jm-kv">
					<span>{ __( 'Current title', 'dev3-ai-wp-job-manager' ) }</span>
					<strong>{ listing.title }</strong>
				</div>
				<div className="dev3jm-kv">
					<span>{ __( 'Current salary text', 'dev3-ai-wp-job-manager' ) }</span>
					<strong>{ [ listing.salary, listing.salary_currency, listing.salary_unit ].filter( Boolean ).join( ' · ' ) || __( 'None', 'dev3-ai-wp-job-manager' ) }</strong>
				</div>
			</Columns>
			<div className="dev3jm-gap-sm" />
			<Inline>
				<Button variant="secondary" icon="refresh" onClick={ () => run( false ) } loading={ busy }>
					{ __( 'Normalise', 'dev3-ai-wp-job-manager' ) }
				</Button>
				<AiButton onClick={ () => run( true ) } disabled={ busy }>
					{ __( 'Ask DEV3 AI', 'dev3-ai-wp-job-manager' ) }
				</AiButton>
			</Inline>
			<ErrorNotice error={ error } onRetry={ () => run( false ) } />
			{ res && form && (
				<div className="dev3jm-draftbox">
					<Inline align="between">
						<strong>{ __( 'Draft', 'dev3-ai-wp-job-manager' ) }</strong>
						<Inline>
							{ sourceBadge( res.source ) }
							<Badge tone="warning">{ __( 'Not saved yet', 'dev3-ai-wp-job-manager' ) }</Badge>
						</Inline>
					</Inline>
					{ res.error && <Notice tone="warning">{ res.error.message }</Notice> }
					{ res.negotiable && <Notice tone="info">{ __( 'The salary says something like "competitive" with no amount. Add a range by hand to get a salary in search results.', 'dev3-ai-wp-job-manager' ) }</Notice> }
					{ res.guessed && <Notice tone="info">{ __( 'The pay period was guessed from the amount. Check the unit.', 'dev3-ai-wp-job-manager' ) }</Notice> }
					<FormGrid>
						<TextField label={ __( 'Title', 'dev3-ai-wp-job-manager' ) } value={ form.title } onChange={ set( 'title' ) } maxLength={ 160 } />
						<Select label={ __( 'Seniority', 'dev3-ai-wp-job-manager' ) } value={ form.seniority } onChange={ set( 'seniority' ) } options={ Object.keys( LEVEL_LABELS ).map( ( k ) => ( { value: k, label: LEVEL_LABELS[ k ] } ) ) } help={ __( 'Used for experienceRequirements in the schema.', 'dev3-ai-wp-job-manager' ) } />
						<NumberField label={ __( 'Salary from', 'dev3-ai-wp-job-manager' ) } value={ form.min } onChange={ set( 'min' ) } min={ 0 } />
						<NumberField label={ __( 'Salary to', 'dev3-ai-wp-job-manager' ) } value={ form.max } onChange={ set( 'max' ) } min={ 0 } />
						<TextField label={ __( 'Currency (ISO)', 'dev3-ai-wp-job-manager' ) } value={ form.currency } onChange={ ( v ) => set( 'currency' )( v.toUpperCase().slice( 0, 3 ) ) } />
						<Select label={ __( 'Pay period', 'dev3-ai-wp-job-manager' ) } value={ form.unit } onChange={ set( 'unit' ) } options={ Object.keys( UNIT_LABELS ).map( ( k ) => ( { value: k, label: UNIT_LABELS[ k ] } ) ) } />
					</FormGrid>
					{ salaryText( draftSalary ) && (
						<p className="dev3jm-reading">
							<Icon name="check" size={ 14 } /> { __( 'Reads as', 'dev3-ai-wp-job-manager' ) } <strong>{ salaryText( draftSalary ) }</strong>
						</p>
					) }
					{ res.changes && res.changes.length > 0 && (
						<ul className="dev3jm-changes">
							{ res.changes.map( ( c ) => (
								<li key={ c }>{ c }</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</Card>
	);
}

/**
 * Job types, categories and tags.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.listing Listing.
 * @param {Function} props.apply   Apply.
 * @param {Function} props.onUsage Usage changed.
 * @return {Element} Panel.
 */
function TaxonomyPanel( { listing, apply, onUsage } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ res, setRes ] = useState( null );
	const [ pick, setPick ] = useState( {} );
	const [ error, setError ] = useState( null );
	const run = () => {
		setBusy( true );
		setError( null );
		request( 'ai/suggest', { method: 'POST', data: { id: listing.id } } )
			.then( ( d ) => {
				setRes( d );
				const p = {};
				d.types.forEach( ( t ) => ( p[ `type:${ t.slug }` ] = true ) );
				d.categories.forEach( ( t ) => ( p[ `cat:${ t.slug }` ] = true ) );
				d.tags.forEach( ( t ) => ( p[ `tag:${ t }` ] = true ) );
				setPick( p );
				onUsage();
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};
	const save = () => {
		const fields = {};
		const types = res.types.filter( ( t ) => pick[ `type:${ t.slug }` ] ).map( ( t ) => t.slug );
		const cats = res.categories.filter( ( t ) => pick[ `cat:${ t.slug }` ] ).map( ( t ) => t.slug );
		const tags = res.tags.filter( ( t ) => pick[ `tag:${ t }` ] );
		if ( types.length ) {
			fields.types = types;
		}
		if ( cats.length ) {
			fields.categories = cats;
		}
		if ( tags.length ) {
			fields.tags = Array.from( new Set( [ ...listing.tags.map( ( t ) => t.name ), ...tags ] ) );
		}
		setSaving( true );
		apply( fields )
			.then( () => {
				toast.success( __( 'Job type and tags saved.', 'dev3-ai-wp-job-manager' ) );
				setRes( null );
			} )
			.finally( () => setSaving( false ) );
	};
	const chip = ( key, label, hint ) => (
		<li key={ key }>
			<Checkbox label={ label } help={ hint } checked={ !! pick[ key ] } onChange={ ( v ) => setPick( { ...pick, [ key ]: v } ) } />
		</li>
	);
	const current = ( list ) => ( list.length ? list.map( ( t ) => t.name ).join( ', ' ) : __( 'None', 'dev3-ai-wp-job-manager' ) );
	return (
		<Card
			tone="ai"
			title={ __( 'Job types & tags', 'dev3-ai-wp-job-manager' ) }
			description={ __( 'Suggests job types and categories from the ones you already use, plus skill tags candidates search for.', 'dev3-ai-wp-job-manager' ) }
			footer={
				res && (
					<Button variant="primary" icon="check" onClick={ save } loading={ saving } disabled={ ! Object.values( pick ).some( Boolean ) }>
						{ __( 'Save selected', 'dev3-ai-wp-job-manager' ) }
					</Button>
				)
			}
		>
			<Columns min={ 200 }>
				<div className="dev3jm-kv">
					<span>{ __( 'Job type', 'dev3-ai-wp-job-manager' ) }</span>
					<strong>{ current( listing.types ) }</strong>
				</div>
				<div className="dev3jm-kv">
					<span>{ __( 'Categories', 'dev3-ai-wp-job-manager' ) }</span>
					<strong>{ current( listing.categories ) }</strong>
				</div>
				<div className="dev3jm-kv">
					<span>{ __( 'Tags', 'dev3-ai-wp-job-manager' ) }</span>
					<strong>{ current( listing.tags ) }</strong>
				</div>
			</Columns>
			<div className="dev3jm-gap-sm" />
			<AiButton loading={ busy } onClick={ run }>
				{ __( 'Suggest with DEV3 AI', 'dev3-ai-wp-job-manager' ) }
			</AiButton>
			<ErrorNotice error={ error } onRetry={ run } />
			{ res && (
				<div className="dev3jm-draftbox">
					<Inline align="between">
						<strong>{ __( 'Suggestions', 'dev3-ai-wp-job-manager' ) }</strong>
						{ sourceBadge( res.source ) }
					</Inline>
					{ res.reason && <p className="dev3jm-muted">{ res.reason }</p> }
					<Columns min={ 200 }>
						<div>
							<p className="dev3jm-label">{ __( 'Job types', 'dev3-ai-wp-job-manager' ) }</p>
							<ul className="dev3jm-picks">{ res.types.length ? res.types.map( ( t ) => chip( `type:${ t.slug }`, t.name, t.reason ) ) : <li className="dev3jm-muted">{ __( 'No change', 'dev3-ai-wp-job-manager' ) }</li> }</ul>
						</div>
						<div>
							<p className="dev3jm-label">{ __( 'Categories', 'dev3-ai-wp-job-manager' ) }</p>
							<ul className="dev3jm-picks">{ res.categories.length ? res.categories.map( ( t ) => chip( `cat:${ t.slug }`, t.name ) ) : <li className="dev3jm-muted">{ __( 'No match', 'dev3-ai-wp-job-manager' ) }</li> }</ul>
						</div>
						<div>
							<p className="dev3jm-label">{ __( 'Tags', 'dev3-ai-wp-job-manager' ) }</p>
							<ul className="dev3jm-picks">{ res.tags.length ? res.tags.map( ( t ) => chip( `tag:${ t }`, t ) ) : <li className="dev3jm-muted">{ __( 'No skills found', 'dev3-ai-wp-job-manager' ) }</li> }</ul>
						</div>
					</Columns>
				</div>
			) }
		</Card>
	);
}

/**
 * JobPosting schema preview.
 *
 * @param {Object} props        Props.
 * @param {Object} props.schema JSON-LD.
 * @param {Array}  props.checks Checks.
 * @return {Element} Panel.
 */
function SchemaPanel( { schema, checks } ) {
	if ( ! schema ) {
		return <EmptyState icon="file" title={ __( 'No schema yet', 'dev3-ai-wp-job-manager' ) } description={ __( 'WP Job Manager prints JobPosting data for published listings.', 'dev3-ai-wp-job-manager' ) } />;
	}
	const tone = { required: 'danger', recommended: 'warning', optional: 'neutral' };
	return (
		<Columns min={ 300 }>
			<Card title={ __( 'Google for Jobs checks', 'dev3-ai-wp-job-manager' ) } description={ __( 'What WP Job Manager prints for this listing, after DEV3 AI improvements.', 'dev3-ai-wp-job-manager' ) }>
				<ul className="dev3jm-checks">
					{ checks.map( ( c ) => (
						<li key={ c.key } className={ c.ok ? 'is-ok' : 'is-missing' }>
							<span className="dev3jm-checks__icon" aria-hidden="true">
								<Icon name={ c.ok ? 'check' : 'x' } size={ 14 } />
							</span>
							<span className="dev3jm-checks__label">{ c.label }</span>
							{ ! c.ok && <Badge tone={ tone[ c.level ] }>{ c.level === 'required' ? __( 'Required', 'dev3-ai-wp-job-manager' ) : c.level === 'recommended' ? __( 'Recommended', 'dev3-ai-wp-job-manager' ) : __( 'Optional', 'dev3-ai-wp-job-manager' ) }</Badge> }
							<span className="screen-reader-text">{ c.ok ? __( 'present', 'dev3-ai-wp-job-manager' ) : __( 'missing', 'dev3-ai-wp-job-manager' ) }</span>
						</li>
					) ) }
				</ul>
			</Card>
			<Card title={ __( 'JSON-LD', 'dev3-ai-wp-job-manager' ) }>
				<pre className="dev3jm-json" tabIndex={ 0 }>
					{ JSON.stringify( { ...schema, description: schema.description ? `${ String( schema.description ).slice( 0, 160 ) }…` : '' }, null, 2 ) }
				</pre>
			</Card>
		</Columns>
	);
}

/**
 * Listing editor: quality checklist + DEV3 AI tools.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Listing id.
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.onUsage  Usage changed.
 * @return {Element} Editor.
 */
export default function ListingEditor( { id, navigate, onUsage } ) {
	const toast = useToast();
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ tab, setTab ] = useState( 'write' );
	const load = () => {
		setError( null );
		request( `listings/${ id }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const apply = ( fields ) =>
		request( `listings/${ id }/apply`, { method: 'POST', data: { fields } } )
			.then( ( d ) => {
				setData( d );
				return d;
			} )
			.catch( ( e ) => {
				toast.error( e );
				throw e;
			} );

	if ( error ) {
		return (
			<>
				<PageHeader title={ __( 'Listing', 'dev3-ai-wp-job-manager' ) } back={ { href: '#/listings', label: __( 'Listings', 'dev3-ai-wp-job-manager' ) } } />
				<ErrorNotice error={ error } onRetry={ load } />
			</>
		);
	}
	if ( ! data ) {
		return <SkeletonCard lines={ 6 } />;
	}
	const { listing, quality } = data;
	const tabs = [
		{ id: 'write', label: __( 'Description', 'dev3-ai-wp-job-manager' ) },
		{ id: 'inclusive', label: __( 'Inclusive language', 'dev3-ai-wp-job-manager' ), badge: listing.inclusive || undefined },
		{ id: 'normalise', label: __( 'Salary & title', 'dev3-ai-wp-job-manager' ) },
		{ id: 'taxonomy', label: __( 'Types & tags', 'dev3-ai-wp-job-manager' ) },
		{ id: 'schema', label: __( 'Schema', 'dev3-ai-wp-job-manager' ) },
	];
	const usage = () => onUsage && onUsage();
	return (
		<>
			<PageHeader
				eyebrow={ __( 'Listing', 'dev3-ai-wp-job-manager' ) }
				title={ listing.title || __( '(no title)', 'dev3-ai-wp-job-manager' ) }
				back={ { href: '#/listings', label: __( 'Listings', 'dev3-ai-wp-job-manager' ) } }
				description={ [ listing.company, listing.location, listing.remote ? __( 'Remote', 'dev3-ai-wp-job-manager' ) : '' ].filter( Boolean ).join( ' · ' ) }
				actions={
					<>
						<Button variant="secondary" icon="edit" href={ listing.edit_url }>
							{ __( 'Edit in WordPress', 'dev3-ai-wp-job-manager' ) }
						</Button>
						<Button variant="ghost" icon="external" href={ listing.view_url } target="_blank" rel="noreferrer">
							{ __( 'View', 'dev3-ai-wp-job-manager' ) }
						</Button>
					</>
				}
			/>
			{ data.draft && (
				<Notice
					tone="ai"
					title={ __( 'A bulk draft is waiting for this listing', 'dev3-ai-wp-job-manager' ) }
					actions={
						<Button size="sm" onClick={ () => navigate( '/review' ) }>
							{ __( 'Open review queue', 'dev3-ai-wp-job-manager' ) }
						</Button>
					}
				/>
			) }
			<div className="dev3jm-editor">
				<aside className="dev3jm-editor__side">
					<Card>
						<div className="dev3jm-score">
							<QualityPill score={ quality.score } />
							<div>
								<strong>{ __( 'Listing quality', 'dev3-ai-wp-job-manager' ) }</strong>
								<StatusBadge status={ listing.status } />
							</div>
						</div>
						<ul className="dev3jm-checks">
							{ Object.keys( quality.checks ).map( ( k ) => {
								const c = quality.checks[ k ];
								return (
									<li key={ k } className={ c.ok ? 'is-ok' : 'is-missing' }>
										<span className="dev3jm-checks__icon" aria-hidden="true">
											<Icon name={ c.ok ? 'check' : 'alert' } size={ 14 } />
										</span>
										<span className="dev3jm-checks__label">
											{ c.label }
											{ c.fix && <small>{ c.fix }</small> }
										</span>
										<span className="dev3jm-checks__pts">
											{ c.points }/{ c.max }
										</span>
									</li>
								);
							} ) }
						</ul>
					</Card>
				</aside>
				<div className="dev3jm-editor__main">
					<div className="dev3jm-tabscroll">
						<Tabs label={ __( 'DEV3 AI tools', 'dev3-ai-wp-job-manager' ) } tabs={ tabs } value={ tab } onChange={ setTab } />
					</div>
					<div className="dev3jm-gap-sm" />
					{ tab === 'write' && <WriterPanel listing={ listing } apply={ apply } onUsage={ usage } /> }
					{ tab === 'inclusive' && <InclusivePanel listing={ listing } apply={ apply } onUsage={ usage } /> }
					{ tab === 'normalise' && <NormalisePanel listing={ listing } apply={ apply } onUsage={ usage } /> }
					{ tab === 'taxonomy' && <TaxonomyPanel listing={ listing } apply={ apply } onUsage={ usage } /> }
					{ tab === 'schema' && <SchemaPanel schema={ data.schema } checks={ data.checks } /> }
				</div>
			</div>
		</>
	);
}
