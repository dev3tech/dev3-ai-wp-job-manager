import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Bulk from './Bulk';
import Dashboard from './Dashboard';
import ListingEditor from './ListingEditor';
import Listings from './Listings';
import Matching from './Matching';
import Review from './Review';
import SettingsTab from './SettingsTab';

const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-wp-job-manager' ), icon: 'dashboard' },
	{ id: 'listings', label: __( 'Listings', 'dev3-ai-wp-job-manager' ), icon: 'list' },
	{ id: 'bulk', label: __( 'Bulk improve', 'dev3-ai-wp-job-manager' ), icon: 'bolt' },
	{ id: 'review', label: __( 'Review queue', 'dev3-ai-wp-job-manager' ), icon: 'inbox' },
	{ id: 'matching', label: __( 'Matching', 'dev3-ai-wp-job-manager' ), icon: 'users' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-wp-job-manager' ), icon: 'settings' },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-wp-job-manager' ), icon: 'key' },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-wp-job-manager' ), icon: 'chart' },
];

export default function App() {
	const [ route, navigate ] = useHashRoute(
		NAV.map( ( n ) => n.id ),
		'dashboard'
	);
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ pending, setPending ] = useState( 0 );

	const load = () => {
		setError( null );
		request( 'settings' ).then( setSettings ).catch( setError );
	};
	useEffect( load, [] );
	const refreshPending = () =>
		request( 'drafts?per_page=1' )
			.then( ( d ) => setPending( d.counts ? d.counts.pending : 0 ) )
			.catch( () => {} );
	useEffect( () => {
		refreshPending();
	}, [ route.id ] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			return next;
		} );

	const bump = () => {
		setMeter( ( n ) => n + 1 );
		refreshPending();
	};
	const nav = NAV.map( ( n ) => ( n.id === 'review' && pending ? { ...n, badge: pending } : n ) );
	const screens = {
		dashboard: () => <Dashboard settings={ settings } navigate={ navigate } />,
		listings: () =>
			route.params[ 0 ] && route.params[ 0 ] !== 'grade' ? <ListingEditor id={ Number( route.params[ 0 ] ) } navigate={ navigate } onUsage={ bump } /> : <Listings navigate={ navigate } grade={ route.params[ 0 ] === 'grade' ? route.params[ 1 ] : '' } />,
		bulk: () => <Bulk navigate={ navigate } ids={ route.params[ 0 ] === 'ids' && route.params[ 1 ] ? route.params[ 1 ].split( ',' ).map( Number ) : [] } onUsage={ bump } />,
		review: () => <Review batch={ route.params[ 0 ] === 'batch' ? Number( route.params[ 1 ] ) : 0 } onChange={ bump } />,
		matching: () => <Matching jobId={ route.params[ 0 ] ? Number( route.params[ 0 ] ) : 0 } navigate={ navigate } onUsage={ bump } />,
		settings: () => <SettingsTab settings={ settings } save={ save } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-wp-job-manager' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-wp-job-manager' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-wp-job-manager' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-wp-job-manager' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Job Listings Assistant for WP Job Manager' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ nav }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-wp-job-manager' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ! settings && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && screens[ route.id ]() }
		</AppShell>
	);
}
