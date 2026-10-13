import assert from 'node:assert/strict';
import path from 'node:path';
import { after, describe, test } from 'node:test';
import { extractSymbols } from '../src/indexer.js';
import { scanProject } from '../src/scanner/scan.js';
import { cleanup, makeRepo, tempDir, writeFiles } from './helpers.js';

const root = tempDir();
const workspacesRoot = path.join(root, 'workspaces');
after(() => cleanup(root));

const scan = (/** @type {string} */ dir) => scanProject(dir, { workspacesRoot });

describe('stack detection (Plan §18–21)', () => {
    test('legacy Laravel 7 + Mix + Bootstrap + jQuery (Plan §5 example 2)', async () => {
        const dir = path.join(root, 'legacy');
        writeFiles(dir, {
            'composer.json': { require: { php: '^7.2.5', 'laravel/framework': '^7.0', 'laravel/ui': '^2.0' }, 'require-dev': { 'phpunit/phpunit': '^8.5' } },
            'composer.lock': { packages: [{ name: 'laravel/framework', version: 'v7.30.6' }], 'packages-dev': [{ name: 'phpunit/phpunit', version: '8.5.33' }] },
            'package.json': { scripts: { dev: 'npm run development', production: 'mix --production' }, devDependencies: { 'laravel-mix': '^5.0.1', bootstrap: '^4.0.0', jquery: '^3.2', sass: '^1.15' } },
            'webpack.mix.js': '',
            'app/Http/Kernel.php': '<?php',
            '.env': 'APP_ENV=local\nDB_CONNECTION=mysql\nDB_PASSWORD=secret\nMIX_PUSHER_APP_KEY=abc',
            'resources/views/welcome.blade.php': '<html></html>',
        });

        const { stack, git, warnings } = await scan(dir);

        assert.equal(stack.backend?.framework, 'Laravel');
        assert.equal(stack.backend?.version, '7.30.6');
        assert.equal(stack.backend?.structure, 'HTTP Kernel (Laravel ≤ 10)');
        assert.equal(stack.frontend?.framework, 'jQuery');
        assert.equal(stack.bundler, 'Laravel Mix');
        assert.deepEqual(stack.css, ['Bootstrap 4.0', 'SCSS']);
        assert.equal(stack.language, 'JavaScript');
        assert.deepEqual(stack.tests, ['PHPUnit']);
        assert.deepEqual(stack.auth, ['Laravel UI']);
        assert.deepEqual(stack.envPrefixes, ['MIX_']);
        assert.equal(stack.database, 'MySQL');
        assert.equal(stack.envShape?.DB_PASSWORD, '<set>');
        assert.ok(stack.commands.some((c) => c.command === 'vendor/bin/phpunit'), 'Laravel 7 without collision uses phpunit directly');
        assert.ok(stack.commands.some((c) => c.command === 'npm run production'), 'Mix builds with "production"');
        assert.equal(git.enabled, false);
        assert.match(warnings.join('\n'), /Laravel 7 is a legacy release/);
        assert.match(warnings.join('\n'), /Not a Git repository/);
    });

    test('Laravel 11 + Inertia React + TypeScript + Vite + Pest + Stripe', async () => {
        const dir = path.join(root, 'modern');
        makeRepo(dir, {
            'composer.json': {
                require: { php: '^8.2', 'laravel/framework': '^11.0', 'inertiajs/inertia-laravel': '^1.0', 'laravel/sanctum': '^4.0', 'stripe/stripe-php': '^13' },
                'require-dev': { 'pestphp/pest': '^2.0', 'larastan/larastan': '^2.0', 'laravel/pint': '^1.0' },
            },
            'package.json': {
                scripts: { build: 'vite build', lint: 'eslint .' },
                devDependencies: { vite: '^5.0', typescript: '^5.4', tailwindcss: '^3.4.1', '@inertiajs/react': '^1.0', react: '^18.2.0', eslint: '^9', prettier: '^3', '@playwright/test': '^1.4' },
            },
            'bootstrap/app.php': '<?php return Application::configure()->withRouting(web: "")->create();',
            'routes/web.php': "<?php Route::post('/stripe/webhook', [WebhookController::class, 'handle']);",
            'tsconfig.json': '{}',
            '.gitignore': '.env\n',
        });
        writeFiles(dir, { '.env': 'DB_CONNECTION=pgsql\nVITE_APP_NAME=x\nSTRIPE_SECRET=sk_test_123' });

        const { stack, git } = await scan(dir);

        assert.equal(stack.backend?.structure, 'Laravel 11+ (bootstrap/app.php)');
        assert.equal(stack.frontend?.framework, 'React');
        assert.equal(stack.bridge, 'Inertia');
        assert.equal(stack.bundler, 'Vite');
        assert.equal(stack.language, 'TypeScript');
        assert.deepEqual(stack.tests, ['Pest']);
        assert.deepEqual(stack.e2e, ['Playwright']);
        assert.deepEqual(stack.staticAnalysis, ['Larastan', 'ESLint', 'TypeScript']);
        assert.deepEqual(stack.formatters, ['Laravel Pint', 'Prettier']);
        assert.deepEqual(stack.auth, ['Laravel Sanctum']);
        assert.deepEqual(stack.payments, ['Stripe']);
        assert.deepEqual(stack.webhookRoutes, ['POST /stripe/webhook']);
        assert.equal(stack.database, 'PostgreSQL');
        assert.ok(stack.commands.some((c) => c.command === 'vendor/bin/pest'));
        assert.ok(git.enabled && git.hasCommits && git.branch === 'main');
        assert.equal(git.envTracked, false);
    });

    test('Next.js App Router with Auth.js and NEXT_PUBLIC_ env', async () => {
        const dir = path.join(root, 'next');
        writeFiles(dir, {
            'package.json': { dependencies: { next: '14.2.3', react: '18.3.1', 'next-auth': '^4' }, devDependencies: { typescript: '^5', tailwindcss: '^3' } },
            'app/page.tsx': 'export default function Home() { return null }',
            'app/api/webhooks/stripe/route.ts': 'export async function POST() {}',
            '.env.local': 'NEXT_PUBLIC_API_URL=x',
            '.env': 'NEXT_PUBLIC_SITE=x\nDATABASE_URL=postgres://u:p@h/db',
        });

        const { stack } = await scan(dir);

        assert.equal(stack.frontend?.framework, 'Next.js');
        assert.equal(stack.frontend?.router, 'app');
        assert.equal(stack.bundler, 'Next.js');
        assert.deepEqual(stack.auth, ['NextAuth']);
        assert.deepEqual(stack.envPrefixes, ['NEXT_PUBLIC_']);
        assert.equal(stack.envShape?.DATABASE_URL, '<set>');
        assert.equal(stack.backend, null);
    });

    test('Create React App (JavaScript, React Router)', async () => {
        const dir = path.join(root, 'cra');
        writeFiles(dir, { 'package.json': { dependencies: { react: '^17.0.2', 'react-router-dom': '^6', 'react-scripts': '5.0.1' }, scripts: { build: 'react-scripts build', test: 'react-scripts test' } } });

        const { stack } = await scan(dir);

        assert.equal(stack.frontend?.framework, 'React');
        assert.equal(stack.frontend?.router, 'React Router');
        assert.equal(stack.bundler, 'Create React App');
        assert.deepEqual(stack.tests, ['Jest']);
        assert.equal(stack.language, 'JavaScript');
    });

    test('plain PHP app without composer', async () => {
        const dir = path.join(root, 'plain');
        writeFiles(dir, { 'index.php': '<?php $db = new mysqli("localhost", "root", "", "shop");' });

        const { stack } = await scan(dir);

        assert.equal(stack.backend?.framework, 'PHP (no framework)');
        assert.equal(stack.database, 'MySQL');
        assert.deepEqual(stack.commands, []);
    });

    test('a tracked .env is flagged', async () => {
        const dir = path.join(root, 'leaky');
        makeRepo(dir, { 'composer.json': { require: { 'laravel/framework': '^10' } }, '.env': 'APP_KEY=x' });

        const { git, warnings } = await scan(dir);

        assert.equal(git.envTracked, true);
        assert.match(warnings[0], /\.env is tracked by Git/);
    });
});

describe('code index symbols (Plan §41)', () => {
    test('PHP classes, models, methods, routes, migrations and tests', () => {
        const model = extractSymbols('app/Models/Booking.php', 'php', '<?php\nclass Booking extends Model\n{\n    public function customer() {}\n}');
        assert.deepEqual(model.map((s) => [s.kind, s.name, s.line]), [['model', 'Booking', 2], ['function', 'customer', 4]]);

        const routes = extractSymbols('routes/web.php', 'php', "<?php\nRoute::get('/bookings', [BookingController::class, 'index'])->name('bookings');\nRoute::resource('rooms', RoomController::class);");
        assert.deepEqual(routes.map((s) => s.name), ['GET /bookings', 'RESOURCE /rooms']);

        const migration = extractSymbols('database/migrations/2024_create_bookings.php', 'php', "<?php\nSchema::create('bookings', function () {});");
        assert.deepEqual(migration.map((s) => [s.kind, s.name]), [['table', 'bookings']]);

        const tests = extractSymbols('tests/Feature/BookingTest.php', 'php', "<?php\nit('creates a booking', function () {});\npublic function test_cancels() {}");
        assert.deepEqual(tests.filter((s) => s.kind === 'test').map((s) => s.name).sort(), ['creates a booking', 'test_cancels']);
    });

    test('React components, hooks, types and Next.js routes', () => {
        const comp = extractSymbols('resources/js/components/booking-card.tsx', 'typescript', 'export function BookingCard() {}\nexport const useBookings = () => {};\nexport interface Booking {}');
        assert.deepEqual(comp.map((s) => [s.kind, s.name]), [['component', 'BookingCard'], ['hook', 'useBookings'], ['type', 'Booking']]);

        const route = extractSymbols('app/(shop)/orders/[id]/route.ts', 'typescript', 'export async function GET() {}\nexport async function DELETE() {}');
        assert.deepEqual(route.filter((s) => s.kind === 'route').map((s) => s.name), ['GET /orders/[id]', 'DELETE /orders/[id]']);

        const page = extractSymbols('pages/api/users.ts', 'typescript', 'export default function handler() {}');
        assert.ok(page.some((s) => s.kind === 'route' && s.name === 'API /api/users'));
    });

    test('symbol details are redacted before leaving the PC', async () => {
        const { indexFile } = await import('../src/indexer.js');
        const dir = path.join(root, 'secret-route');
        writeFiles(dir, { 'routes/web.php': "<?php Route::get('/x', fn () => 'sk_" + "live_51Habcdefghijklmnopqrst');" });

        const entry = indexFile(dir, 'routes/web.php');
        assert.ok(entry && !JSON.stringify(entry).includes('sk_live_51H'));
    });
});
