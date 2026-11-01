import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Columns, EmptyState, ErrorNotice, Icon, Inline, Notice, PageHeader, SkeletonCard, Stat, StatGrid } from '../core3/ui';

/**
 * Status row.
 *
 * @param {Object}  props       Props.
 * @param {boolean} props.ok    Ok.
 * @param {string}  props.label Label.
 * @param {string}  props.hint  Hint.
 * @return {Element} Row.
 */
function StatusRow( { ok, label, hint } ) {
	return (
		<li className="dev3jm-status__row">
			<span className={ `dev3jm-status__icon ${ ok ? 'is-ok' : 'is-off' }` } aria-hidden="true">
				<Icon name={ ok ? 'check' : 'info' } size={ 14 } />
			</span>
			<span className="dev3jm-status__text">
				<strong>{ label }</strong>
				{ hint && <span>{ hint }</span> }
			</span>
		</li>
	);
}

/**
 * Dashboard: listing quality KPIs, WPJM status and quick actions.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Navigate.
 * @return {Element} Dashboard.
 */
export default function Dashboard( { navigate } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const load = () => {
		setError( null );
		request( 'overview' ).then( setData ).catch( setError );
	};
	useEffect( load, [] );

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! data ) {
		return (
			<>
				<PageHeader title={ __( 'Dashboard', 'dev3-ai-wp-job-manager' ) } />
				<SkeletonCard lines={ 4 } />
			</>
		);
	}
	const { wpjm, counts, drafts, ai } = data;
	const noKey = ! ai.key_set;

	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-wp-job-manager' ) }
				description={ __( 'How good your WP Job Manager listings are, and what to fix first.', 'dev3-ai-wp-job-manager' ) }
				actions={
					wpjm.active && (
						<Button variant="primary" icon="bolt" onClick={ () => navigate( '/bulk' ) }>
							{ __( 'Bulk improve', 'dev3-ai-wp-job-manager' ) }
						</Button>
					)
				}
			/>
			{ ! wpjm.active && (
				<Card>
					<EmptyState
						icon="box"
						title={ __( 'WP Job Manager is not active', 'dev3-ai-wp-job-manager' ) }
						description={ __( 'This add-on improves listings created with the free WP Job Manager plugin. Install and activate it, then come back here.', 'dev3-ai-wp-job-manager' ) }
						action={
							<Button variant="primary" href={ wpjm.install_url }>
								{ __( 'Install WP Job Manager', 'dev3-ai-wp-job-manager' ) }
							</Button>
						}
					/>
				</Card>
			) }
			{ wpjm.active && noKey && (
				<Notice
					tone="ai"
					title={ __( 'Connect DEV3 AI', 'dev3-ai-wp-job-manager' ) }
					actions={
						<Button size="sm" variant="ai" href="#/api">
							{ __( 'Add API key', 'dev3-ai-wp-job-manager' ) }
						</Button>
					}
				>
					{ __( 'Quality scores, salary normalisation, tag suggestions, the inclusive word list and the better schema already work. Add a Google Gemini key to write descriptions and explain candidate matches with DEV3 AI.', 'dev3-ai-wp-job-manager' ) }
				</Notice>
			) }
			{ wpjm.active && (
				<>
					<StatGrid min={ 150 }>
						<Stat label={ __( 'Listings', 'dev3-ai-wp-job-manager' ) } value={ counts.listings } icon="list" />
						<Stat label={ __( 'Average quality', 'dev3-ai-wp-job-manager' ) } value={ `${ counts.average }/100` } icon="chart" tone={ counts.average >= 80 ? 'success' : counts.average >= 55 ? 'warning' : 'danger' } />
						<Stat label={ __( 'Need attention', 'dev3-ai-wp-job-manager' ) } value={ counts.poor } hint={ __( 'Quality under 55', 'dev3-ai-wp-job-manager' ) } icon="alert" tone={ counts.poor ? 'danger' : 'neutral' } />
						<Stat label={ __( 'Salary missing or unclear', 'dev3-ai-wp-job-manager' ) } value={ counts.no_salary } icon="info" tone={ counts.no_salary ? 'warning' : 'neutral' } />
						<Stat label={ __( 'Exclusionary wording', 'dev3-ai-wp-job-manager' ) } value={ counts.inclusive } icon="users" tone={ counts.inclusive ? 'warning' : 'neutral' } />
						<Stat label={ __( 'Drafts to review', 'dev3-ai-wp-job-manager' ) } value={ drafts.pending } icon="inbox" tone={ drafts.pending ? 'brand' : 'neutral' } />
					</StatGrid>
					<div className="dev3jm-gap" />
					<Columns min={ 300 }>
						<Card title={ __( 'Quality breakdown', 'dev3-ai-wp-job-manager' ) } description={ __( 'Scores update every time a listing is saved.', 'dev3-ai-wp-job-manager' ) }>
							<ul className="dev3jm-grades">
								{ [
									[ 'good', __( 'Good (80+)', 'dev3-ai-wp-job-manager' ), 'success' ],
									[ 'fair', __( 'Fair (55-79)', 'dev3-ai-wp-job-manager' ), 'warning' ],
									[ 'poor', __( 'Poor (under 55)', 'dev3-ai-wp-job-manager' ), 'danger' ],
								].map( ( [ key, label, tone ] ) => (
									<li key={ key }>
										<Badge tone={ tone } dot>
											{ label }
										</Badge>
										<span className="dev3jm-grades__n">{ counts[ key ] }</span>
										<Button size="sm" variant="link" onClick={ () => navigate( `/listings/grade/${ key }` ) }>
											{ __( 'View', 'dev3-ai-wp-job-manager' ) }
										</Button>
									</li>
								) ) }
							</ul>
							{ counts.unscored > 0 && (
								<p className="dev3jm-muted">
									{ sprintf(
										/* translators: %d: count */
										__( '%d listings are scored the first time you open the Listings screen.', 'dev3-ai-wp-job-manager' ),
										counts.unscored
									) }
								</p>
							) }
						</Card>
						<Card title={ __( 'WP Job Manager setup', 'dev3-ai-wp-job-manager' ) }>
							<ul className="dev3jm-status">
								<StatusRow ok={ wpjm.supported } label={ sprintf( /* translators: %s: version */ __( 'WP Job Manager %s', 'dev3-ai-wp-job-manager' ), wpjm.version || '' ) } hint={ wpjm.supported ? __( 'Active and supported', 'dev3-ai-wp-job-manager' ) : __( 'Update to 1.36 or later for salary fields', 'dev3-ai-wp-job-manager' ) } />
								<StatusRow ok={ wpjm.salary } label={ __( 'Salary field', 'dev3-ai-wp-job-manager' ) } hint={ wpjm.salary ? __( 'Enabled in Job Listings → Settings', 'dev3-ai-wp-job-manager' ) : __( 'Turn on "Enable Job Salary" in Job Listings → Settings', 'dev3-ai-wp-job-manager' ) } />
								<StatusRow ok={ wpjm.tags } label={ __( 'Job tags', 'dev3-ai-wp-job-manager' ) } hint={ wpjm.tags_ours ? __( 'Provided by this plugin', 'dev3-ai-wp-job-manager' ) : wpjm.tags ? __( 'From the Job Tags add-on', 'dev3-ai-wp-job-manager' ) : __( 'Off (Settings → Job tags)', 'dev3-ai-wp-job-manager' ) } />
								<StatusRow ok={ wpjm.resumes } label={ __( 'Resume Manager', 'dev3-ai-wp-job-manager' ) } hint={ wpjm.resumes ? sprintf( /* translators: %d: count */ __( '%d resumes ready for matching', 'dev3-ai-wp-job-manager' ), data.resumes ) : __( 'Optional paid add-on: enables candidate matching', 'dev3-ai-wp-job-manager' ) } />
							</ul>
						</Card>
						<Card title={ __( 'Next steps', 'dev3-ai-wp-job-manager' ) }>
							<ul className="dev3jm-steps">
								<li>
									<Button variant="link" onClick={ () => navigate( '/listings/grade/poor' ) }>
										{ __( 'Fix the weakest listings', 'dev3-ai-wp-job-manager' ) }
									</Button>
									<span>{ __( 'Open a listing to rewrite, normalise and tag it.', 'dev3-ai-wp-job-manager' ) }</span>
								</li>
								<li>
									<Button variant="link" onClick={ () => navigate( '/bulk' ) }>
										{ __( 'Improve many listings at once', 'dev3-ai-wp-job-manager' ) }
									</Button>
									<span>{ __( 'Runs in the background and fills the review queue.', 'dev3-ai-wp-job-manager' ) }</span>
								</li>
								<li>
									<Button variant="link" onClick={ () => navigate( '/review' ) }>
										{ __( 'Review drafts', 'dev3-ai-wp-job-manager' ) }
									</Button>
									<span>{ __( 'Nothing changes on your board until you accept.', 'dev3-ai-wp-job-manager' ) }</span>
								</li>
								<li>
									<Button variant="link" href={ wpjm.jobs_url }>
										{ __( 'Open WP Job Manager listings', 'dev3-ai-wp-job-manager' ) }
									</Button>
									<span>{ __( 'The Quality column shows each score.', 'dev3-ai-wp-job-manager' ) }</span>
								</li>
							</ul>
						</Card>
					</Columns>
					{ data.bulk && (
						<>
							<div className="dev3jm-gap" />
							<Card title={ __( 'Last bulk run', 'dev3-ai-wp-job-manager' ) }>
								<Inline align="between">
									<span>
										{ sprintf(
											/* translators: 1: processed, 2: total */
											__( '%1$d of %2$d listings processed', 'dev3-ai-wp-job-manager' ),
											data.bulk.processed,
											data.bulk.total
										) }
									</span>
									<Button size="sm" onClick={ () => navigate( `/review/batch/${ data.bulk.id }` ) }>
										{ __( 'Review its drafts', 'dev3-ai-wp-job-manager' ) }
									</Button>
								</Inline>
							</Card>
						</>
					) }
				</>
			) }
		</>
	);
}
