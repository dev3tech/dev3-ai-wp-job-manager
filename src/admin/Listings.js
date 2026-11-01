import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ErrorNotice, Inline, PageHeader, ResponsiveTable, Select, TextField } from '../core3/ui';
import { ISSUE_LABELS, QualityPill, StatusBadge, formatDate } from './shared';

const PER_PAGE = 20;

/**
 * WPJM listings with quality scores, filters and selection for bulk improve.
 *
 * @param {Object}   props          Props.
 * @param {Function} props.navigate Navigate.
 * @param {string}   props.grade    Initial grade filter.
 * @return {Element} Listings.
 */
export default function Listings( { navigate, grade: initialGrade = '' } ) {
	const [ grade, setGrade ] = useState( [ 'good', 'fair', 'poor' ].includes( initialGrade ) ? initialGrade : '' );
	const [ status, setStatus ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ sort, setSort ] = useState( { key: 'quality', dir: 'asc' } );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );

	const load = () => {
		setError( null );
		const params = new URLSearchParams( { page, per_page: PER_PAGE, grade, status, search: query, orderby: sort.key, order: sort.dir } );
		request( `listings?${ params.toString() }` ).then( setData ).catch( setError );
	};
	useEffect( load, [ page, grade, status, query, sort.key, sort.dir ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const rows = data ? data.items : [];
	const allSelected = rows.length > 0 && rows.every( ( r ) => selected.includes( r.id ) );
	const toggle = ( id ) => setSelected( selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] );
	const stop = ( e ) => e.stopPropagation();

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-wp-job-manager' ),
			width: 44,
			render: ( r ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ stop }>
					<Checkbox label={ sprintf( /* translators: %s: job title */ __( 'Select %s', 'dev3-ai-wp-job-manager' ), r.title ) } checked={ selected.includes( r.id ) } onChange={ () => toggle( r.id ) } className="dev3jm-hidelabel" />
				</span>
			),
		},
		{
			key: 'title',
			label: __( 'Listing', 'dev3-ai-wp-job-manager' ),
			primary: true,
			sortable: true,
			render: ( r ) => (
				<div className="dev3jm-cell-title">
					<strong>{ r.title || __( '(no title)', 'dev3-ai-wp-job-manager' ) }</strong>
					<span>{ [ r.company, r.location ].filter( Boolean ).join( ' · ' ) }</span>
				</div>
			),
		},
		{ key: 'quality', label: __( 'Quality', 'dev3-ai-wp-job-manager' ), sortable: true, nowrap: true, render: ( r ) => <QualityPill score={ r.quality } /> },
		{
			key: 'issues',
			label: __( 'To fix', 'dev3-ai-wp-job-manager' ),
			render: ( r ) =>
				r.issues.length ? (
					<span className="dev3jm-chips">
						{ r.issues.slice( 0, 3 ).map( ( i ) => (
							<Badge key={ i } tone={ i === 'inclusive' ? 'warning' : 'neutral' }>
								{ ISSUE_LABELS[ i ] || i }
							</Badge>
						) ) }
						{ r.issues.length > 3 && <Badge>{ `+${ r.issues.length - 3 }` }</Badge> }
					</span>
				) : (
					<Badge tone="success">{ __( 'All good', 'dev3-ai-wp-job-manager' ) }</Badge>
				),
		},
		{ key: 'salary', label: __( 'Salary', 'dev3-ai-wp-job-manager' ), hideOnMobile: true, render: ( r ) => r.salary || <span className="dev3jm-muted">{ __( 'None', 'dev3-ai-wp-job-manager' ) }</span> },
		{ key: 'status', label: __( 'Status', 'dev3-ai-wp-job-manager' ), render: ( r ) => <StatusBadge status={ r.status } /> },
		{ key: 'date', label: __( 'Posted', 'dev3-ai-wp-job-manager' ), sortable: true, hideOnMobile: true, nowrap: true, render: ( r ) => formatDate( r.date ) },
	];

	return (
		<>
			<PageHeader
				title={ __( 'Listings', 'dev3-ai-wp-job-manager' ) }
				description={ __( 'Every WP Job Manager listing with its quality score. Open one to improve it with DEV3 AI.', 'dev3-ai-wp-job-manager' ) }
				actions={
					selected.length > 0 && (
						<Button variant="ai" icon="sparkles" onClick={ () => navigate( `/bulk/ids/${ selected.join( ',' ) }` ) }>
							{ sprintf( /* translators: %d: count */ __( 'Improve %d selected', 'dev3-ai-wp-job-manager' ), selected.length ) }
						</Button>
					)
				}
			/>
			<Card flush>
				<div className="dev3jm-toolbar">
					<form
						className="dev3jm-toolbar__search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							setPage( 1 );
							setQuery( search );
						} }
					>
						<TextField label={ __( 'Search listings', 'dev3-ai-wp-job-manager' ) } hideLabel type="search" value={ search } onChange={ setSearch } placeholder={ __( 'Search titles and descriptions…', 'dev3-ai-wp-job-manager' ) } />
						<Button type="submit" icon="search">
							{ __( 'Search', 'dev3-ai-wp-job-manager' ) }
						</Button>
					</form>
					<Inline gap={ 8 }>
						<Select
							label={ __( 'Quality', 'dev3-ai-wp-job-manager' ) }
							hideLabel
							value={ grade }
							onChange={ ( v ) => {
								setGrade( v );
								setPage( 1 );
							} }
							options={ [
								{ value: '', label: __( 'All quality levels', 'dev3-ai-wp-job-manager' ) },
								{ value: 'poor', label: __( 'Poor (under 55)', 'dev3-ai-wp-job-manager' ) },
								{ value: 'fair', label: __( 'Fair (55-79)', 'dev3-ai-wp-job-manager' ) },
								{ value: 'good', label: __( 'Good (80+)', 'dev3-ai-wp-job-manager' ) },
							] }
						/>
						<Select
							label={ __( 'Status', 'dev3-ai-wp-job-manager' ) }
							hideLabel
							value={ status }
							onChange={ ( v ) => {
								setStatus( v );
								setPage( 1 );
							} }
							options={ [
								{ value: '', label: __( 'All statuses', 'dev3-ai-wp-job-manager' ) },
								{ value: 'publish', label: __( 'Live', 'dev3-ai-wp-job-manager' ) },
								{ value: 'pending', label: __( 'Pending', 'dev3-ai-wp-job-manager' ) },
								{ value: 'draft', label: __( 'Draft', 'dev3-ai-wp-job-manager' ) },
								{ value: 'expired', label: __( 'Expired', 'dev3-ai-wp-job-manager' ) },
							] }
						/>
					</Inline>
				</div>
				<div className="dev3jm-bulkbar">
					<Checkbox
						label={ selected.length ? sprintf( /* translators: %d: count */ __( '%d selected', 'dev3-ai-wp-job-manager' ), selected.length ) : __( 'Select all on this page', 'dev3-ai-wp-job-manager' ) }
						checked={ allSelected }
						onChange={ () => setSelected( allSelected ? selected.filter( ( id ) => ! rows.some( ( r ) => r.id === id ) ) : Array.from( new Set( [ ...selected, ...rows.map( ( r ) => r.id ) ] ) ) ) }
					/>
				</div>
				<ErrorNotice error={ error } onRetry={ load } />
				<ResponsiveTable
					caption={ __( 'Job listings', 'dev3-ai-wp-job-manager' ) }
					columns={ columns }
					rows={ rows }
					loading={ ! data && ! error }
					sort={ sort }
					onSortChange={ ( s ) => {
						setSort( s );
						setPage( 1 );
					} }
					onRowClick={ ( r ) => navigate( `/listings/${ r.id }` ) }
					pagination={ data ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'list',
						title: query || grade || status ? __( 'No listings match these filters', 'dev3-ai-wp-job-manager' ) : __( 'No job listings yet', 'dev3-ai-wp-job-manager' ),
						description: __( 'Listings created in WP Job Manager (admin or the front-end submit form) appear here with a quality score.', 'dev3-ai-wp-job-manager' ),
					} }
				/>
			</Card>
		</>
	);
}
