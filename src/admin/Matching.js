import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, EmptyState, ErrorNotice, Inline, Notice, PageHeader, ProgressBar, Select, SkeletonCard, Stack, useToast } from '../core3/ui';
import { QualityPill, formatDate } from './shared';

const CRITERIA = {
	skills: __( 'Skills', 'dev3-ai-wp-job-manager' ),
	experience: __( 'Experience', 'dev3-ai-wp-job-manager' ),
	role: __( 'Role fit', 'dev3-ai-wp-job-manager' ),
	location: __( 'Location', 'dev3-ai-wp-job-manager' ),
};

/**
 * One candidate.
 *
 * @param {Object} props   Props.
 * @param {Object} props.c Candidate row.
 * @return {Element} Card.
 */
function Candidate( { c } ) {
	return (
		<Card className="dev3jm-cand">
			<div className="dev3jm-cand__head">
				<QualityPill score={ c.score } />
				<div className="dev3jm-cand__who">
					<strong>
						{ c.edit_url ? (
							<a href={ c.edit_url }>{ c.name || __( 'Candidate', 'dev3-ai-wp-job-manager' ) }</a>
						) : (
							c.name
						) }
					</strong>
					<span>{ [ c.headline, c.location, c.years ? sprintf( /* translators: %s: years */ __( '%s yrs', 'dev3-ai-wp-job-manager' ), c.years ) : '' ].filter( Boolean ).join( ' · ' ) }</span>
				</div>
				<Badge tone={ c.source === 'ai' ? 'brand' : 'neutral' }>{ c.source === 'ai' ? __( 'Rules + DEV3 AI', 'dev3-ai-wp-job-manager' ) : __( 'Rules', 'dev3-ai-wp-job-manager' ) }</Badge>
			</div>
			<div className="dev3jm-cand__crit">
				{ Object.keys( CRITERIA ).map( ( k ) => (
					<div key={ k } className="dev3jm-crit">
						<span className="dev3jm-crit__label">
							{ CRITERIA[ k ] }
							<span>{ c.criteria[ k ] }</span>
						</span>
						<ProgressBar value={ c.criteria[ k ] } label={ `${ CRITERIA[ k ] } ${ c.criteria[ k ] }/100` } tone={ c.criteria[ k ] >= 70 ? 'success' : c.criteria[ k ] >= 45 ? 'warning' : 'danger' } />
					</div>
				) ) }
			</div>
			<p className="dev3jm-cand__sum">{ c.summary }</p>
			<div className="dev3jm-cand__lists">
				{ c.strengths.length > 0 && (
					<div>
						<p className="dev3jm-label">{ __( 'Strengths', 'dev3-ai-wp-job-manager' ) }</p>
						<ul>
							{ c.strengths.map( ( s ) => (
								<li key={ s }>{ s }</li>
							) ) }
						</ul>
					</div>
				) }
				{ c.gaps.length > 0 && (
					<div>
						<p className="dev3jm-label">{ __( 'Gaps to ask about', 'dev3-ai-wp-job-manager' ) }</p>
						<ul>
							{ c.gaps.map( ( s ) => (
								<li key={ s }>{ s }</li>
							) ) }
						</ul>
					</div>
				) }
			</div>
			{ c.scored_at && <p className="dev3jm-muted">{ sprintf( /* translators: %s: date */ __( 'Scored %s', 'dev3-ai-wp-job-manager' ), formatDate( c.scored_at ) ) }</p> }
		</Card>
	);
}

/**
 * Candidate matching (Resume Manager).
 *
 * @param {Object}   props          Props.
 * @param {number}   props.jobId    Selected job.
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.onUsage  Usage changed.
 * @return {Element} Matching.
 */
export default function Matching( { jobId, navigate, onUsage } ) {
	const toast = useToast();
	const [ meta, setMeta ] = useState( null );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		request( 'matching' ).then( setMeta ).catch( setError );
	}, [] );
	useEffect( () => {
		setData( null );
		if ( jobId && meta && meta.active ) {
			request( `matching/${ jobId }` ).then( setData ).catch( setError );
		}
	}, [ jobId, meta ] );

	const score = ( useAi ) => {
		setBusy( true );
		request( `matching/${ jobId }/score`, { method: 'POST', data: { use_ai: useAi, resume_ids: data ? data.items.slice( 0, 10 ).map( ( c ) => c.resume_id ) : [] } } )
			.then( ( r ) => {
				setData( { ...data, items: r.items } );
				if ( r.error ) {
					toast.warning( r.error.message );
				} else {
					toast.success( sprintf( /* translators: %d: count */ __( '%d candidates scored.', 'dev3-ai-wp-job-manager' ), r.scored ) );
				}
				onUsage();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( false ) );
	};

	const header = <PageHeader title={ __( 'Candidate matching', 'dev3-ai-wp-job-manager' ) } description={ __( 'Rank Resume Manager resumes against a job, with the reasons behind every score.', 'dev3-ai-wp-job-manager' ) } />;
	if ( error ) {
		return (
			<>
				{ header }
				<ErrorNotice error={ error } />
			</>
		);
	}
	if ( ! meta ) {
		return (
			<>
				{ header }
				<SkeletonCard lines={ 4 } />
			</>
		);
	}
	if ( ! meta.active ) {
		return (
			<>
				{ header }
				<Card>
					<EmptyState
						icon="users"
						title={ __( 'Resume Manager is not active', 'dev3-ai-wp-job-manager' ) }
						description={ __( 'Candidate matching reads resumes from the WP Job Manager Resume Manager add-on (sold by wpjobmanager.com). Everything else in this plugin works without it.', 'dev3-ai-wp-job-manager' ) }
						action={
							<Button href="https://wpjobmanager.com/add-ons/resume-manager/" target="_blank" rel="noreferrer" icon="external">
								{ __( 'About Resume Manager', 'dev3-ai-wp-job-manager' ) }
							</Button>
						}
					/>
				</Card>
			</>
		);
	}
	return (
		<>
			{ header }
			<Stack gap={ 16 }>
				<Notice tone="info" title={ __( 'Decision support only. Review every candidate yourself.', 'dev3-ai-wp-job-manager' ) }>
					{ __( 'Scores use only job-relevant data: skills, experience, past roles and location. Names, contact details, photos and protected attributes are never sent to DEV3 AI or scored, and nothing here changes a resume or application.', 'dev3-ai-wp-job-manager' ) }
				</Notice>
				<Card>
					<Inline align="between">
						<Select
							label={ __( 'Job listing', 'dev3-ai-wp-job-manager' ) }
							value={ jobId ? String( jobId ) : '' }
							onChange={ ( v ) => navigate( v ? `/matching/${ v }` : '/matching' ) }
							options={ [ { value: '', label: __( 'Choose a job…', 'dev3-ai-wp-job-manager' ) }, ...meta.jobs.map( ( j ) => ( { value: String( j.id ), label: j.title } ) ) ] }
						/>
						{ data && (
							<Inline>
								<Button onClick={ () => score( false ) } loading={ busy } icon="refresh">
									{ __( 'Rescore top 10', 'dev3-ai-wp-job-manager' ) }
								</Button>
								{ meta.ai && (
									<AiButton onClick={ () => score( true ) } disabled={ busy }>
										{ __( 'Explain top 10 with DEV3 AI', 'dev3-ai-wp-job-manager' ) }
									</AiButton>
								) }
							</Inline>
						) }
					</Inline>
					{ data && (
						<p className="dev3jm-muted">
							{ sprintf(
								/* translators: 1: years, 2: skills */
								__( 'This job asks for about %1$d years of experience and: %2$s.', 'dev3-ai-wp-job-manager' ),
								data.job.years,
								data.job.skills.join( ', ' ) || __( 'no specific skills', 'dev3-ai-wp-job-manager' )
							) }
						</p>
					) }
				</Card>
				{ ! jobId && <EmptyState icon="users" title={ __( 'Pick a job to rank candidates', 'dev3-ai-wp-job-manager' ) } description={ sprintf( /* translators: %d: count */ __( '%d resumes are available.', 'dev3-ai-wp-job-manager' ), meta.resumes ) } /> }
				{ jobId > 0 && ! data && <SkeletonCard lines={ 4 } /> }
				{ data && data.items.length === 0 && <EmptyState icon="users" title={ __( 'No resumes yet', 'dev3-ai-wp-job-manager' ) } description={ __( 'Published and hidden Resume Manager resumes appear here.', 'dev3-ai-wp-job-manager' ) } /> }
				{ data && data.items.length > 0 && (
					<div className="dev3jm-cands">
						{ data.items.map( ( c ) => (
							<Candidate key={ c.resume_id } c={ c } />
						) ) }
					</div>
				) }
			</Stack>
		</>
	);
}
