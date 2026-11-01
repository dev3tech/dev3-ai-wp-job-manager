import { initPanel, initSalary, post, readForm, replacePhrase, safePreview } from '../../src/frontend/submit';

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );

const form = () => {
	document.body.innerHTML = `
		<form id="submit-job-form">
			<fieldset class="fieldset-job_title"><input id="job_title" value="Barista"></fieldset>
			<fieldset class="fieldset-job_type"><select id="job_type"><option value="">Choose</option><option value="3">Full Time</option><option value="4">Part Time</option></select></fieldset>
			<fieldset class="fieldset-job_description"><textarea id="job_description">We want a young rockstar barista.</textarea></fieldset>
			<fieldset class="fieldset-job_salary"><div class="field"><input id="job_salary" value=""></div></fieldset>
			<fieldset class="fieldset-job_tags"><input id="job_tags" value="Coffee"></fieldset>
			<div id="dev3jm-assistant" hidden></div>
		</form>`;
};

beforeEach( () => {
	window.dev3jmSubmit = { restUrl: '/wp-json/dev3jm/v1/public/', writer: true, inclusive: true, suggest: true, salary: true, tone: 'friendly' };
	form();
	window.fetch = jest.fn();
} );

const reply = ( data, ok = true ) => Promise.resolve( { ok, json: () => Promise.resolve( data ) } );

describe( 'submit form helpers', () => {
	it( 'reads the WPJM form fields', () => {
		document.getElementById( 'job_type' ).value = '4';
		const f = readForm();
		expect( f.title ).toBe( 'Barista' );
		expect( f.content ).toContain( 'rockstar' );
		expect( f.types ).toBe( 'Part Time' );
	} );

	it( 'replaces phrases case-insensitively', () => {
		expect( replacePhrase( 'Young people, young ideas', 'young', 'motivated' ) ).toBe( 'Motivated people, motivated ideas' );
	} );

	it( 'keeps only safe tags in previews', () => {
		const div = document.createElement( 'div' );
		div.append( safePreview( '<p>Hi <img src=x onerror=alert(1)><script>bad()</script></p><ul><li>One</li></ul>' ) );
		expect( div.innerHTML ).toBe( '<p>Hi </p><ul><li>One</li></ul>' );
	} );

	it( 'posts JSON same-origin to the public route and surfaces errors', async () => {
		window.fetch.mockReturnValueOnce( reply( { code: 'dev3jm_rate_limited', message: 'Too many requests.' }, false ) );
		await expect( post( 'salary', { salary: '1' } ) ).rejects.toEqual( { code: 'dev3jm_rate_limited', message: 'Too many requests.' } );
		const [ url, opts ] = window.fetch.mock.calls[ 0 ];
		expect( url ).toBe( '/wp-json/dev3jm/v1/public/salary' );
		expect( opts.credentials ).toBe( 'same-origin' );
		window.dev3jmSubmit.restUrl = '/index.php?rest_route=/dev3jm/v1/public/';
		window.fetch.mockReturnValueOnce( reply( {} ) );
		await post( 'write', {} );
		expect( window.fetch.mock.calls[ 1 ][ 0 ] ).toBe( '/index.php?rest_route=/dev3jm/v1/public/write' );
	} );
} );

describe( 'assistant panel', () => {
	it( 'moves under the description and fixes flagged wording', async () => {
		const mount = document.getElementById( 'dev3jm-assistant' );
		initPanel( mount );
		expect( mount.hidden ).toBe( false );
		expect( mount.previousElementSibling.className ).toContain( 'fieldset-job_description' );
		expect( mount.textContent ).not.toContain( 'DEV3' );
		window.fetch.mockReturnValueOnce( reply( { issues: [ { phrase: 'young', suggestion: 'motivated', reason: 'Age-coded' } ], source: 'rules' } ) );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Check wording' )
			.click();
		await flush();
		await flush();
		const fix = Array.from( mount.querySelectorAll( 'button' ) ).find( ( b ) => b.textContent === 'Replace' );
		fix.click();
		expect( document.getElementById( 'job_description' ).value ).toBe( 'We want a motivated rockstar barista.' );
	} );

	it( 'writes a description only when the visitor chooses to use it', async () => {
		const mount = document.getElementById( 'dev3jm-assistant' );
		initPanel( mount );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Help me write it' )
			.click();
		expect( document.getElementById( 'dev3jm-tone' ).value ).toBe( 'friendly' );
		window.fetch.mockReturnValueOnce( reply( { title: 'Barista', content: '<p>Make great coffee.</p>' } ) );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Write description' )
			.click();
		await flush();
		await flush();
		expect( document.getElementById( 'job_description' ).value ).toContain( 'rockstar' );
		expect( mount.querySelector( '.dev3jm-assist__preview' ).textContent ).toBe( 'Make great coffee.' );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Use this text' )
			.click();
		expect( document.getElementById( 'job_description' ).value ).toBe( '<p>Make great coffee.</p>' );
	} );

	it( 'selects the suggested job type and merges tags', async () => {
		const mount = document.getElementById( 'dev3jm-assistant' );
		initPanel( mount );
		window.fetch.mockReturnValueOnce( reply( { types: [ { id: 4, name: 'Part Time', slug: 'part-time' } ], tags: [ 'coffee', 'Customer service' ], categories: [] } ) );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Suggest type & tags' )
			.click();
		await flush();
		await flush();
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Select' )
			.click();
		expect( document.getElementById( 'job_type' ).value ).toBe( '4' );
		Array.from( mount.querySelectorAll( 'button' ) )
			.find( ( b ) => b.textContent === 'Add tags' )
			.click();
		expect( document.getElementById( 'job_tags' ).value ).toBe( 'Coffee, Customer service' );
	} );

	it( 'shows a live salary reading', async () => {
		jest.useFakeTimers();
		initSalary();
		const input = document.getElementById( 'job_salary' );
		input.value = '$50k-70k a year';
		input.dispatchEvent( new window.Event( 'input' ) );
		window.fetch.mockReturnValueOnce( reply( { salary: { min: 50000, max: 70000, currency: 'USD', unit: 'YEAR' }, reading: 'USD 50,000 – 70,000 per year', negotiable: false } ) );
		jest.advanceTimersByTime( 700 );
		jest.useRealTimers();
		await flush();
		await flush();
		expect( document.getElementById( 'dev3jm-salary-hint' ).textContent ).toBe( 'Reads as USD 50,000 – 70,000 per year' );
	} );
} );
