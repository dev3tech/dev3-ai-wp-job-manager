import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Card, ErrorNotice, Inline, Modal, Notice, PageHeader, ResponsiveTable, Tabs, useToast } from '../core3/ui';
import { LEVEL_LABELS, formatDate, htmlToText, salaryText, textToHtml } from './shared';

const PER_PAGE = 20;

const SOURCE_LABELS = {
	normalise: __( 'Salary & title', 'dev3-ai-wp-job-manager' ),
	description: __( 'Description', 'dev3-ai-wp-job-manager' ),
	inclusive: __( 'Wording', 'dev3-ai-wp-job-manager' ),
	taxonomy: __( 'Types & tags', 'dev3-ai-wp-job-manager' ),
};

const KEY_LABELS = {
	title: __( 'Title', 'dev3-ai-wp-job-manager' ),
	content: __( 'Description', 'dev3-ai-wp-job-manager' ),
	fixes: __( 'Wording fixes', 'dev3-ai-wp-job-manager' ),
	salary: __( 'Salary', 'dev3-ai-wp-job-manager' ),
	seniority: __( 'Seniority', 'dev3-ai-wp-job-manager' ),
	types: __( 'Job type', 'dev3-ai-wp-job-manager' ),
	categories: __( 'Categories', 'dev3-ai-wp-job-manager' ),
	tags: __( 'Tags', 'dev3-ai-wp-job-manager' ),
};

/**
 * Summary rows for non-text changes.
 *
 * @param {Object} props   Props.
 * @param {Object} props.d Draft row.
 * @return {Element} List.
 */
function OtherChanges( { d } ) {
	const draft = d.draft;
	const o = d.original || {};
	const rows = [];
	if ( draft.salary ) {
		rows.push( [ 'salary', [ o.salary && o.salary.label, o.salary && o.salary.currency, o.salary && o.salary.unit ].filter( Boolean ).join( ' · ' ) || '—', salaryText( draft.salary ) ] );
	}
	if ( draft.seniority ) {
		rows.push( [ 'seniority', '—', LEVEL_LABELS[ draft.seniority ] || draft.seniority ] );
	}
	if ( draft.types ) {
		rows.push( [ 'types', ( o.types || [] ).join( ', ' ) || '—', draft.types.join( ', ' ) ] );
	}
	if ( draft.categories ) {
		rows.push( [ 'categories', '—', draft.categories.join( ', ' ) ] );
	}
	if ( draft.tags ) {
		rows.push( [ 'tags', ( o.tags || [] ).join( ', ' ) || '—', draft.tags.join( ', ' ) ] );
	}
	if ( draft.fixes ) {
		rows.push( [ 'fixes', '', draft.fixes.map( ( f ) => `“${ f.phrase }” → ${ f.suggestion ? `“${ f.suggestion }”` : __( '(remove)', 'dev3-ai-wp-job-manager' ) }` ).join( '; ' ) ] );
	}
	if ( ! rows.length ) {
		return null;
	}
	return (
		<dl className="dev3jm-changeset">
			{ rows.map( ( [ key, before, after ] ) => (
				<div key={ key }>
					<dt>{ KEY_LABELS[ key ] }</dt>
					<dd>
						{ before && <del>{ before }</del> }
						<ins>{ after }</ins>
					</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * Review modal content.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.d        Draft.
 * @param {Function} props.onDone   Done.
 * @return {Element} Review.
 */
function DraftReview( { d, onDone } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );
	const hasText = d.draft.title !== undefined || d.draft.content !== undefined;
	const act = ( action, draft ) => {
		setBusy( action );
		request( `drafts/${ d.id }/${ action }`, { method: 'POST', data: draft ? { draft } : {} } )
			.then( () => {
				toast.success( action === 'accept' ? __( 'Changes saved to the listing.', 'dev3-ai-wp-job-manager' ) : __( 'Draft rejected. The listing was not changed.', 'dev3-ai-wp-job-manager' ) );
				onDone();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};
	const fields = [];
	const original = {};
	const draftText = {};
	if ( d.draft.title !== undefined ) {
		fields.push( { key: 'title', label: KEY_LABELS.title, maxLength: 160 } );
		original.title = d.original.title || '';
		draftText.title = d.draft.title;
	}
	if ( d.draft.content !== undefined ) {
		fields.push( { key: 'content', label: KEY_LABELS.content, multiline: true } );
		original.content = htmlToText( d.original.content || '' );
		draftText.content = htmlToText( d.draft.content );
	}
	const accept = ( values ) => {
		const next = { ...d.draft };
		if ( values && values.title !== undefined ) {
			next.title = values.title;
		}
		if ( values && values.content !== undefined && values.content !== draftText.content ) {
			next.content = textToHtml( values.content );
		}
		act( 'accept', next );
	};
	return (
		<>
			<Inline>
				{ Object.keys( d.meta.sources || {} ).map( ( k ) => (
					<Badge key={ k } tone={ d.meta.sources[ k ] === 'ai' ? 'brand' : 'neutral' }>
						{ `${ SOURCE_LABELS[ k ] || k }: ${ d.meta.sources[ k ] === 'ai' ? 'DEV3 AI' : __( 'rules', 'dev3-ai-wp-job-manager' ) }` }
					</Badge>
				) ) }
			</Inline>
			{ d.meta.notes && d.meta.notes.length > 0 && <Notice tone="info">{ d.meta.notes.join( ' ' ) }</Notice> }
			<OtherChanges d={ d } />
			{ hasText ? (
				<AiDraftReview fields={ fields } original={ original } draft={ draftText } meta={ { model: d.meta.model, tokens: d.meta.tokens } } onAccept={ accept } accepting={ busy === 'accept' } onReject={ () => act( 'reject' ) } acceptLabel={ __( 'Accept all changes', 'dev3-ai-wp-job-manager' ) } />
			) : (
				<Inline align="end">
					<Button variant="ghost" icon="x" onClick={ () => act( 'reject' ) } loading={ busy === 'reject' }>
						{ __( 'Reject', 'dev3-ai-wp-job-manager' ) }
					</Button>
					<Button variant="primary" icon="check" onClick={ () => accept( null ) } loading={ busy === 'accept' }>
						{ __( 'Accept changes', 'dev3-ai-wp-job-manager' ) }
					</Button>
				</Inline>
			) }
		</>
	);
}

/**
 * Review queue.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.batch    Batch filter.
 * @param {Function} props.onChange Called after accept/reject.
 * @return {Element} Review.
 */
export default function Review( { batch = 0, onChange } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState( 'pending' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const load = () => {
		setError( null );
		const params = new URLSearchParams( { status, page, per_page: PER_PAGE, batch_id: batch || 0 } );
		request( `drafts?${ params.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ status, page, batch ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const safe = () => {
		setBusy( true );
		request( 'drafts/accept-safe', { method: 'POST', data: { batch_id: batch || 0 } } )
			.then( ( r ) => {
				toast.success( sprintf( /* translators: 1: applied, 2: kept */ __( 'Safe changes applied to %1$d listings. %2$d drafts with text changes are still waiting.', 'dev3-ai-wp-job-manager' ), r.applied, r.kept ) );
				load();
				onChange();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	const counts = data ? data.counts : {};
	const tabs = [
		{ id: 'pending', label: __( 'Waiting', 'dev3-ai-wp-job-manager' ), badge: counts.pending || undefined },
		{ id: 'accepted', label: __( 'Accepted', 'dev3-ai-wp-job-manager' ) },
		{ id: 'rejected', label: __( 'Rejected', 'dev3-ai-wp-job-manager' ) },
	];
	const columns = [
		{ key: 'title', label: __( 'Listing', 'dev3-ai-wp-job-manager' ), primary: true, render: ( r ) => <strong>{ r.title }</strong> },
		{
			key: 'ops',
			label: __( 'Changes', 'dev3-ai-wp-job-manager' ),
			render: ( r ) => (
				<span className="dev3jm-chips">
					{ r.ops.map( ( o ) => (
						<Badge key={ o } tone={ o === 'content' || o === 'fixes' ? 'brand' : 'neutral' }>
							{ KEY_LABELS[ o ] || o }
						</Badge>
					) ) }
				</span>
			),
		},
		{ key: 'batch_id', label: __( 'Run', 'dev3-ai-wp-job-manager' ), hideOnMobile: true, render: ( r ) => ( r.batch_id ? `#${ r.batch_id }` : '—' ) },
		{ key: 'created_at', label: __( 'Created', 'dev3-ai-wp-job-manager' ), nowrap: true, render: ( r ) => formatDate( r.created_at ) },
	];
	return (
		<>
			<PageHeader
				title={ __( 'Review queue', 'dev3-ai-wp-job-manager' ) }
				description={ batch ? sprintf( /* translators: %d: run id */ __( 'Drafts from bulk run #%d. Accept, edit or reject each one.', 'dev3-ai-wp-job-manager' ), batch ) : __( 'Drafts from bulk runs. Accept, edit or reject each one; listings only change when you accept.', 'dev3-ai-wp-job-manager' ) }
				actions={
					status === 'pending' &&
					counts.pending > 0 && (
						<Button onClick={ safe } loading={ busy } icon="check" title={ __( 'Applies salary, title, seniority, type and tag changes; description rewrites stay for review.', 'dev3-ai-wp-job-manager' ) }>
							{ __( 'Accept safe changes', 'dev3-ai-wp-job-manager' ) }
						</Button>
					)
				}
			/>
			<div className="dev3jm-tabscroll">
				<Tabs
					label={ __( 'Draft status', 'dev3-ai-wp-job-manager' ) }
					tabs={ tabs }
					value={ status }
					onChange={ ( v ) => {
						setStatus( v );
						setPage( 1 );
					} }
				/>
			</div>
			{ batch > 0 && (
				<p>
					<Button variant="link" href="#/review">
						{ __( 'Show drafts from all runs', 'dev3-ai-wp-job-manager' ) }
					</Button>
				</p>
			) }
			<Card flush>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Drafts', 'dev3-ai-wp-job-manager' ) }
					columns={ columns }
					rows={ data ? data.items : [] }
					loading={ ! data && ! error }
					onRowClick={ status === 'pending' ? ( r ) => setOpen( r ) : undefined }
					rowActions={
						status === 'pending'
							? ( r ) => (
									<Button
										size="sm"
										onClick={ ( e ) => {
											e.stopPropagation();
											setOpen( r );
										} }
									>
										{ __( 'Review', 'dev3-ai-wp-job-manager' ) }
									</Button>
							  )
							: undefined
					}
					pagination={ data ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'inbox',
						title: status === 'pending' ? __( 'Nothing to review', 'dev3-ai-wp-job-manager' ) : __( 'No drafts here yet', 'dev3-ai-wp-job-manager' ),
						description: __( 'Start a bulk improve run to create drafts for many listings at once.', 'dev3-ai-wp-job-manager' ),
						action: (
							<Button variant="primary" href="#/bulk">
								{ __( 'Bulk improve', 'dev3-ai-wp-job-manager' ) }
							</Button>
						),
					} }
				/>
			</Card>
			<Modal open={ !! open } onClose={ () => setOpen( null ) } size="lg" title={ open ? sprintf( /* translators: %s: listing */ __( 'DEV3 AI · Review “%s”', 'dev3-ai-wp-job-manager' ), open.title ) : '' }>
				{ open && (
					<DraftReview
						d={ open }
						onDone={ () => {
							setOpen( null );
							load();
							onChange();
						} }
					/>
				) }
			</Modal>
		</>
	);
}
