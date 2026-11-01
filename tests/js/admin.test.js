import apiFetch from '@wordpress/api-fetch';
import Bulk from '../../src/admin/Bulk';
import Dashboard from '../../src/admin/Dashboard';
import ListingEditor from '../../src/admin/ListingEditor';
import Listings from '../../src/admin/Listings';
import Matching from '../../src/admin/Matching';
import { applyFix } from '../../src/admin/Metabox';
import Review from '../../src/admin/Review';
import { gradeOf, htmlToText, salaryText, textToHtml } from '../../src/admin/shared';
import { ToastProvider } from '../../src/core3/ui';
import { change, click, render } from './render';

const route = ( map ) =>
	apiFetch.mockImplementation( ( { url, method = 'GET', data } ) => {
		const path = url.replace( /^.*dev3jm\/v1\//, '' );
		const key = Object.keys( map ).find( ( k ) => {
			const [ m, p ] = k.split( ' ' );
			return m === method && path.split( '?' )[ 0 ] === p;
		} );
		if ( ! key ) {
			return new Promise( () => {} );
		}
		const v = map[ key ];
		return Promise.resolve( typeof v === 'function' ? v( data, path ) : v );
	} );

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

const LISTING = {
	id: 7,
	title: 'Sr. JS Ninja!!!',
	content: '<p>We need a young rockstar.</p><ul><li>React</li></ul>',
	status: 'publish',
	company: 'Acme',
	company_tagline: '',
	location: 'Berlin, Germany',
	remote: false,
	salary: '$50k-70k/yr',
	salary_currency: '',
	salary_unit: '',
	types: [],
	categories: [],
	tags: [ { id: 1, name: 'React', slug: 'react' } ],
	inclusive: 2,
	edit_url: '/wp-admin/post.php?post=7&action=edit',
	view_url: '/job/7',
};
const DETAIL = {
	listing: LISTING,
	quality: { score: 42, grade: 'poor', issues: [ 'salary' ], checks: { salary: { ok: false, points: 3, max: 15, label: 'Salary is not a number', fix: 'Add numbers' }, company: { ok: true, points: 5, max: 5, label: 'Company name', fix: '' } } },
	schema: { '@type': 'JobPosting', title: 'Sr. JS Ninja', description: '<p>x</p>' },
	checks: [ { key: 'baseSalary', level: 'recommended', ok: true, label: 'Salary as numbers' } ],
	draft: null,
};

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	window.location.hash = '';
} );

describe( 'helpers', () => {
	it( 'round-trips job HTML through editable text', () => {
		const html = '<p>Hello team.</p><h3>What you will do</h3><ul><li>Ship &lt;features&gt;</li><li>Review code</li></ul>';
		const text = htmlToText( html );
		expect( text ).toBe( 'Hello team.\n\nWhat you will do:\n- Ship <features>\n- Review code' );
		expect( textToHtml( text ) ).toBe( '<p>Hello team.</p>\n<h3>What you will do</h3>\n<ul>\n<li>Ship &lt;features&gt;</li>\n<li>Review code</li>\n</ul>\n' );
	} );

	it( 'formats salaries and grades', () => {
		expect( salaryText( { min: 50000, max: 70000, currency: 'USD', unit: 'YEAR' } ) ).toMatch( /^USD 50,000 – 70,000 per year$/ );
		expect( salaryText( { min: null, max: 90000, currency: 'EUR', unit: 'MONTH' } ) ).toMatch( /up to 90,000 per month/ );
		expect( salaryText( null ) ).toBe( '' );
		expect( [ gradeOf( 90 ), gradeOf( 60 ), gradeOf( 10 ) ] ).toEqual( [ 'good', 'fair', 'poor' ] );
	} );

	it( 'applies wording fixes keeping a leading capital', () => {
		expect( applyFix( '<p>Young team. We are young.</p>', 'young', 'motivated' ) ).toBe( '<p>Motivated team. We are motivated.</p>' );
		expect( applyFix( 'a rockstar dev', 'rockstar', '' ) ).toBe( 'a dev' );
	} );
} );

describe( 'screens', () => {
	it( 'dashboard asks to install WP Job Manager when it is missing', async () => {
		route( { 'GET overview': { wpjm: { active: false, install_url: '/plugin-install.php' }, counts: {}, drafts: {}, ai: { key_set: false } } } );
		const view = await render( wrap( <Dashboard navigate={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'WP Job Manager is not active' ) ).toBeTruthy();
		expect( view.getByText( 'Install WP Job Manager' ).closest( 'a' ).getAttribute( 'href' ) ).toBe( '/plugin-install.php' );
	} );

	it( 'dashboard shows KPIs and the connect notice without a key', async () => {
		route( {
			'GET overview': {
				wpjm: { active: true, supported: true, version: '2.4.8', salary: true, tags: true, tags_ours: true, resumes: false, jobs_url: '/edit.php' },
				counts: { listings: 12, average: 61, poor: 3, fair: 5, good: 4, no_salary: 6, inclusive: 2, unscored: 0 },
				drafts: { pending: 4 },
				resumes: 0,
				ai: { key_set: false },
				bulk: null,
			},
		} );
		const view = await render( wrap( <Dashboard navigate={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Connect DEV3 AI' ) ).toBeTruthy();
		expect( view.getByText( '61/100' ) ).toBeTruthy();
		expect( view.getByText( 'Provided by this plugin' ) ).toBeTruthy();
	} );

	it( 'listings table shows quality and sends server-side filters', async () => {
		const calls = [];
		route( {
			'GET listings': ( d, path ) => {
				calls.push( path );
				return { items: [ { id: 7, title: 'Frontend Developer', company: 'Acme', location: 'Berlin', quality: 48, grade: 'poor', issues: [ 'salary', 'inclusive' ], salary: '', status: 'publish', date: '2026-10-01T00:00:00Z' } ], total: 1 };
			},
		} );
		const nav = jest.fn();
		const view = await render( wrap( <Listings navigate={ nav } grade="poor" /> ) );
		await view.flush();
		expect( calls[ 0 ] ).toContain( 'grade=poor' );
		expect( calls[ 0 ] ).toContain( 'orderby=quality' );
		expect( view.getByText( 'Exclusionary wording' ) ).toBeTruthy();
		// Selecting a row offers bulk improve with the ids.
		await click( view.$( 'td input[type="checkbox"]' ) );
		await click( view.getByText( 'Improve 1 selected' ) );
		expect( nav ).toHaveBeenCalledWith( '/bulk/ids/7' );
	} );

	it( 'listing editor normalises salary and saves only on accept', async () => {
		const applied = [];
		route( {
			'GET listings/7': DETAIL,
			'POST ai/normalise': { title: 'Senior JavaScript Developer', seniority: 'senior', salary: { min: 50000, max: 70000, currency: 'USD', unit: 'YEAR', label: '50,000 – 70,000' }, reading: 'USD 50,000 – 70,000 per year', changes: [ 'Removed "ninja"' ], negotiable: false, guessed: false, source: 'rules' },
			'POST listings/7/apply': ( data ) => {
				applied.push( data.fields );
				return DETAIL;
			},
		} );
		const view = await render( wrap( <ListingEditor id={ 7 } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Listing quality' ) ).toBeTruthy();
		await click( view.getByText( 'Salary & title' ) );
		await click( view.getByText( 'Normalise' ) );
		await view.flush();
		expect( view.getByText( 'Not saved yet' ) ).toBeTruthy();
		expect( applied ).toHaveLength( 0 );
		await click( view.getByText( 'Save to listing' ) );
		await view.flush();
		expect( applied[ 0 ] ).toEqual( { title: 'Senior JavaScript Developer', seniority: 'senior', salary: { min: 50000, max: 70000, currency: 'USD', unit: 'YEAR' } } );
	} );

	it( 'listing editor applies only the inclusive fixes that stay ticked', async () => {
		const applied = [];
		route( {
			'GET listings/7': DETAIL,
			'POST ai/inclusive': {
				issues: [
					{ phrase: 'young', reason: 'Age-coded', suggestion: 'motivated', source: 'rule' },
					{ phrase: 'rockstar', reason: 'Jargon', suggestion: 'expert', source: 'rule' },
				],
				source: 'rules',
			},
			'POST listings/7/apply': ( data ) => {
				applied.push( data.fields );
				return DETAIL;
			},
		} );
		const view = await render( wrap( <ListingEditor id={ 7 } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		await click( view.getByText( 'Inclusive language' ) );
		await click( view.getByText( 'Word list only' ) );
		await view.flush();
		const boxes = view.$$( '.dev3jm-issue input[type="checkbox"]' );
		expect( boxes ).toHaveLength( 2 );
		await click( boxes[ 1 ] );
		await click( view.getByText( 'Apply 1 fixes' ) );
		await view.flush();
		expect( applied[ 0 ] ).toEqual( { fixes: [ { phrase: 'young', suggestion: 'motivated' } ] } );
	} );

	it( 'listing editor shows the AI description draft and saves edited text as HTML', async () => {
		const applied = [];
		route( {
			'GET listings/7': DETAIL,
			'POST ai/write': { title: 'Senior Frontend Developer', content: '<p>Join us.</p>\n<h3>What you will do</h3>\n<ul>\n<li>Build UI</li>\n</ul>\n', seniority: 'senior', skills: [ 'React' ], source: 'ai', meta: { model: 'gemini-2.5-flash', tokens: 900 } },
			'POST listings/7/apply': ( data ) => {
				applied.push( data.fields );
				return DETAIL;
			},
		} );
		const view = await render( wrap( <ListingEditor id={ 7 } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		await click( view.getByText( 'Write with DEV3 AI' ) );
		await view.flush();
		expect( view.getByText( 'Preview' ) ).toBeTruthy();
		await click( view.getByText( 'Save to listing' ) );
		await view.flush();
		expect( applied[ 0 ].title ).toBe( 'Senior Frontend Developer' );
		expect( applied[ 0 ].content ).toContain( '<li>Build UI</li>' );
	} );

	it( 'bulk improve posts the chosen filter and operations', async () => {
		const posted = [];
		route( {
			'GET bulk/recent': { active: null, recent: [] },
			'GET usage/month': { enabled: true, key_set: false, cap_reached: false },
			'POST bulk': ( data ) => {
				posted.push( data );
				return { id: 9, status: 'queued', total: 3, processed: 0, percent: 0, done: 0, failed: 0, errors: [] };
			},
		} );
		const view = await render( wrap( <Bulk navigate={ () => {} } ids={ [] } onUsage={ () => {} } /> ) );
		await view.flush();
		await click( view.$( 'input[value="no_salary"]' ) );
		await click( view.getByText( 'Start bulk improve' ) );
		await view.flush();
		expect( posted[ 0 ].filter ).toBe( 'no_salary' );
		expect( posted[ 0 ].ops ).toEqual( [ 'inclusive', 'salary', 'title', 'taxonomy' ] );
		expect( posted[ 0 ].use_ai ).toBe( false );
		expect( view.getByText( 'Review drafts' ) ).toBeTruthy();
	} );

	it( 'review queue accepts and rejects drafts', async () => {
		const actions = [];
		const draft = { id: 3, post_id: 7, title: 'Frontend Developer', ops: [ 'salary', 'tags' ], status: 'pending', original: { salary: { label: '50k' }, tags: [] }, draft: { salary: { min: 50000, max: 50000, currency: 'USD', unit: 'YEAR' }, tags: [ 'React' ] }, meta: { sources: { normalise: 'rules' } }, created_at: '2026-10-20T00:00:00Z', batch_id: 9 };
		route( {
			'GET drafts': { items: [ draft ], total: 1, counts: { pending: 1, accepted: 0, rejected: 0 } },
			'POST drafts/3/accept': ( data ) => {
				actions.push( [ 'accept', data ] );
				return { listing: {} };
			},
			'POST drafts/accept-safe': () => {
				actions.push( [ 'safe' ] );
				return { applied: 1, kept: 0 };
			},
		} );
		const view = await render( wrap( <Review batch={ 0 } onChange={ () => {} } /> ) );
		await view.flush();
		await click( view.getByText( 'Review' ) );
		await view.flush();
		expect( view.getByText( 'USD 50,000 per year' ) ).toBeTruthy();
		await click( view.getByText( 'Accept changes' ) );
		await view.flush();
		expect( actions[ 0 ][ 0 ] ).toBe( 'accept' );
		expect( actions[ 0 ][ 1 ].draft.tags ).toEqual( [ 'React' ] );
		await click( view.getByText( 'Accept safe changes' ) );
		await view.flush();
		expect( actions[ 1 ] ).toEqual( [ 'safe' ] );
	} );

	it( 'matching explains the Resume Manager requirement', async () => {
		route( { 'GET matching': { active: false, resumes: 0, jobs: [], ai: false } } );
		const view = await render( wrap( <Matching jobId={ 0 } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Resume Manager is not active' ) ).toBeTruthy();
	} );

	it( 'matching ranks candidates with the decision-support notice', async () => {
		route( {
			'GET matching': { active: true, resumes: 2, jobs: [ { id: 7, title: 'Frontend Developer' } ], ai: true },
			'GET matching/7': {
				job: { id: 7, title: 'Frontend Developer', skills: [ 'React' ], years: 3 },
				items: [ { resume_id: 11, name: 'Candidate A', headline: 'Frontend Engineer', location: 'Berlin', years: 5, score: 82, criteria: { skills: 90, experience: 100, role: 60, location: 100 }, strengths: [ 'Has React' ], gaps: [], summary: 'Strong fit.', source: 'rules', edit_url: '/r/11' } ],
			},
		} );
		const view = await render( wrap( <Matching jobId={ 7 } navigate={ () => {} } onUsage={ () => {} } /> ) );
		await view.flush();
		await view.flush();
		expect( view.getByText( 'Decision support only. Review every candidate yourself.' ) ).toBeTruthy();
		expect( view.getByText( 'Candidate A' ) ).toBeTruthy();
		expect( view.getByText( 'Explain top 10 with DEV3 AI' ) ).toBeTruthy();
		expect( view.$$( '[role="progressbar"]' ) ).toHaveLength( 4 );
	} );
} );

describe( 'navigation helpers', () => {
	it( 'changes the listing grade filter', async () => {
		const calls = [];
		route( {
			'GET listings': ( d, path ) => {
				calls.push( path );
				return { items: [], total: 0 };
			},
		} );
		const view = await render( wrap( <Listings navigate={ () => {} } /> ) );
		await view.flush();
		await change( view.$$( 'select' )[ 0 ], 'good' );
		await view.flush();
		expect( calls[ calls.length - 1 ] ).toContain( 'grade=good' );
		expect( view.getByText( 'No listings match these filters' ) ).toBeTruthy();
	} );
} );
