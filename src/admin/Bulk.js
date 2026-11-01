import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, Columns, ErrorNotice, JobProgress, Notice, PageHeader, ResponsiveTable, Stack, Toggle, useAiStatus, useToast } from '../core3/ui';
import { OP_LABELS, formatDate } from './shared';

const FILTERS = [
	{ id: 'low', label: __( 'Low quality', 'dev3-ai-wp-job-manager' ), hint: __( 'Score under 70', 'dev3-ai-wp-job-manager' ) },
	{ id: 'no_salary', label: __( 'Salary missing or unclear', 'dev3-ai-wp-job-manager' ), hint: __( 'No numbers Google can read', 'dev3-ai-wp-job-manager' ) },
	{ id: 'no_type', label: __( 'Missing type or tags', 'dev3-ai-wp-job-manager' ), hint: __( 'Hard to find in filters', 'dev3-ai-wp-job-manager' ) },
	{ id: 'inclusive', label: __( 'Exclusionary wording', 'dev3-ai-wp-job-manager' ), hint: __( 'Flagged by the word list', 'dev3-ai-wp-job-manager' ) },
	{ id: 'all', label: __( 'All listings', 'dev3-ai-wp-job-manager' ), hint: __( 'Live, pending, draft and expired', 'dev3-ai-wp-job-manager' ) },
];

const OPS_HELP = {
	description: __( 'Restructures the ad into summary, duties, requirements and benefits.', 'dev3-ai-wp-job-manager' ),
	inclusive: __( 'Replaces flagged phrases with neutral wording.', 'dev3-ai-wp-job-manager' ),
	salary: __( 'Numbers, ISO currency and pay period in WPJM salary fields.', 'dev3-ai-wp-job-manager' ),
	title: __( 'Removes hype words, emojis and pay; expands abbreviations.', 'dev3-ai-wp-job-manager' ),
	taxonomy: __( 'Fills empty job types/categories and adds skill tags.', 'dev3-ai-wp-job-manager' ),
};

/**
 * Bulk improve wizard: pick listings and operations, run a background job, then review.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Navigate.
 * @param {number[]} props.ids      Preselected ids.
 * @param {Function} props.onUsage  Usage changed.
 * @return {Element} Bulk.
 */
export default function Bulk( { navigate, ids = [], onUsage } ) {
	const toast = useToast();
	const status = useAiStatus();
	const [ filter, setFilter ] = useState( ids.length ? 'selected' : 'low' );
	const [ ops, setOps ] = useState( { description: false, inclusive: true, salary: true, title: true, taxonomy: true } );
	const [ useAi, setUseAi ] = useState( true );
	const [ job, setJob ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ recent, setRecent ] = useState( null );

	const loadRecent = () =>
		request( 'bulk/recent' )
			.then( ( d ) => {
				setRecent( d.recent );
				if ( d.active && ! job ) {
					setJob( d.active );
				}
			} )
			.catch( () => setRecent( [] ) );
	useEffect( () => {
		loadRecent();
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const aiReady = status && status.key_set && status.enabled && ! status.cap_reached;
	const chosen = Object.keys( ops ).filter( ( k ) => ops[ k ] );
	const start = () => {
		setBusy( true );
		setError( null );
		request( 'bulk', { method: 'POST', data: { filter, ids, ops: chosen, use_ai: useAi && aiReady } } )
			.then( ( j ) => {
				setJob( j );
				toast.info( sprintf( /* translators: %d: count */ __( 'Improving %d listings in the background.', 'dev3-ai-wp-job-manager' ), j.total ) );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	};

	const filters = ids.length ? [ { id: 'selected', label: sprintf( /* translators: %d: count */ __( '%d selected listings', 'dev3-ai-wp-job-manager' ), ids.length ), hint: __( 'From the Listings screen', 'dev3-ai-wp-job-manager' ) }, ...FILTERS ] : FILTERS;

	return (
		<>
			<PageHeader title={ __( 'Bulk improve', 'dev3-ai-wp-job-manager' ) } description={ __( 'Improve many listings in one run. Every change becomes a draft in the review queue; nothing is published until you accept it.', 'dev3-ai-wp-job-manager' ) } />
			<Stack gap={ 16 }>
				{ job ? (
					<Card title={ __( 'Bulk run', 'dev3-ai-wp-job-manager' ) }>
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'Creating drafts', 'dev3-ai-wp-job-manager' ) }
							onDone={ () => {
								onUsage();
								loadRecent();
							} }
						/>
						<div className="dev3jm-gap-sm" />
						<Button variant="primary" icon="inbox" onClick={ () => navigate( `/review/batch/${ job.id }` ) }>
							{ __( 'Review drafts', 'dev3-ai-wp-job-manager' ) }
						</Button>{ ' ' }
						<Button variant="ghost" onClick={ () => setJob( null ) }>
							{ __( 'Start another run', 'dev3-ai-wp-job-manager' ) }
						</Button>
					</Card>
				) : (
					<>
						<Card title={ __( '1. Which listings?', 'dev3-ai-wp-job-manager' ) }>
							<div className="dev3jm-choices" role="radiogroup" aria-label={ __( 'Which listings', 'dev3-ai-wp-job-manager' ) }>
								{ filters.map( ( f ) => (
									<label key={ f.id } className={ `dev3jm-choice${ filter === f.id ? ' is-on' : '' }` }>
										<input type="radio" name="dev3jm-filter" value={ f.id } checked={ filter === f.id } onChange={ () => setFilter( f.id ) } />
										<span>
											<strong>{ f.label }</strong>
											<small>{ f.hint }</small>
										</span>
									</label>
								) ) }
							</div>
						</Card>
						<Card title={ __( '2. What to improve?', 'dev3-ai-wp-job-manager' ) }>
							<Columns min={ 260 }>
								{ Object.keys( OP_LABELS ).map( ( k ) => (
									<Checkbox key={ k } label={ OP_LABELS[ k ] } help={ OPS_HELP[ k ] } checked={ ops[ k ] } onChange={ ( v ) => setOps( { ...ops, [ k ]: v } ) } />
								) ) }
							</Columns>
						</Card>
						<Card
							tone="ai"
							title={ __( '3. Run', 'dev3-ai-wp-job-manager' ) }
							footer={
								<Button variant="ai" icon="sparkles" onClick={ start } loading={ busy } disabled={ ! chosen.length }>
									{ __( 'Start bulk improve', 'dev3-ai-wp-job-manager' ) }
								</Button>
							}
						>
							<Toggle
								label={ __( 'Use DEV3 AI', 'dev3-ai-wp-job-manager' ) }
								checked={ useAi && !! aiReady }
								disabled={ ! aiReady }
								onChange={ setUseAi }
								help={ aiReady ? __( 'Rewrites and suggestions use DEV3 AI; salary and titles use rules first and AI only for odd cases.', 'dev3-ai-wp-job-manager' ) : __( 'No API key or AI paused: the run uses the built-in rules and templates.', 'dev3-ai-wp-job-manager' ) }
							/>
							{ ops.description && useAi && aiReady && <Notice tone="info">{ __( 'Rewriting descriptions uses about 1,500 tokens per listing.', 'dev3-ai-wp-job-manager' ) }</Notice> }
							<ErrorNotice error={ error } onRetry={ start } />
						</Card>
					</>
				) }
				<Card title={ __( 'Recent runs', 'dev3-ai-wp-job-manager' ) } flush>
					<ResponsiveTable
						caption={ __( 'Recent bulk runs', 'dev3-ai-wp-job-manager' ) }
						loading={ recent === null }
						rows={ recent || [] }
						columns={ [
							{ key: 'id', label: __( 'Run', 'dev3-ai-wp-job-manager' ), primary: true, render: ( r ) => `#${ r.id }` },
							{ key: 'created_at', label: __( 'Started', 'dev3-ai-wp-job-manager' ), render: ( r ) => formatDate( r.created_at ) },
							{ key: 'ops', label: __( 'Improvements', 'dev3-ai-wp-job-manager' ), render: ( r ) => ( ( r.args && r.args.ops ) || [] ).map( ( o ) => OP_LABELS[ o ] || o ).join( ', ' ) },
							{ key: 'total', label: __( 'Listings', 'dev3-ai-wp-job-manager' ), render: ( r ) => `${ r.processed }/${ r.total }` },
							{ key: 'status', label: __( 'Status', 'dev3-ai-wp-job-manager' ), render: ( r ) => <Badge tone={ r.status === 'done' ? 'success' : r.status === 'failed' ? 'danger' : 'info' }>{ r.status }</Badge> },
						] }
						onRowClick={ ( r ) => navigate( `/review/batch/${ r.id }` ) }
						empty={ { icon: 'bolt', title: __( 'No bulk runs yet', 'dev3-ai-wp-job-manager' ), description: __( 'Runs you start appear here with a link to their drafts.', 'dev3-ai-wp-job-manager' ) } }
					/>
				</Card>
			</Stack>
		</>
	);
}
