// @ts-check
import { envShape } from '../redact.js';

/**
 * Stack detection (Plan §18–21). Every value comes from a real file in the
 * project; nothing is assumed from what "most projects" use.
 *
 * @typedef {import('./files.js').ProjectFiles} ProjectFiles
 */

/**
 * @typedef {{
 *   name: string,
 *   languages: string[],
 *   backend: { framework: string, version: string | null, constraint: string | null, php: string | null, structure?: string } | null,
 *   frontend: { framework: string, version: string | null, router?: string | null, meta?: string | null } | null,
 *   bridge: string | null,
 *   bundler: string | null,
 *   css: string[],
 *   language: string | null,
 *   packageManager: string | null,
 *   node: string | null,
 *   database: string | null,
 *   tests: string[],
 *   e2e: string[],
 *   staticAnalysis: string[],
 *   formatters: string[],
 *   auth: string[],
 *   payments: string[],
 *   webhookRoutes: string[],
 *   envPrefixes: string[],
 *   docker: string[],
 *   ci: string[],
 *   designSystem: { tailwindConfig: string | null, componentDirs: string[], cssVariables: boolean },
 *   commands: { label: string, command: string }[],
 *   envShape: Record<string, string> | null,
 *   warnings: string[],
 * }} StackProfile
 */

/**
 * @param {ProjectFiles} files
 * @param {string} folderName
 * @returns {StackProfile}
 */
export function detectStack(files, folderName) {
    const composer = files.json('composer.json');
    const composerLock = files.json('composer.lock');
    const pkg = files.json('package.json');
    const env = files.readEnvForShape('.env');
    const shape = env !== null ? envShape(env) : null;
    const exampleShape = shape ?? (files.read('.env.example') !== null ? envShape(/** @type {string} */ (files.read('.env.example'))) : null);

    const php = phpPackages(composer, composerLock);
    const node = nodePackages(files, pkg);

    /** @type {string[]} */
    const warnings = [];

    const backend = detectBackend(files, composer, php);
    const next = node.version('next');
    const frontend = detectFrontend(files, node, next);
    const bridge = php.has('inertiajs/inertia-laravel') || node.has('@inertiajs/react') || node.has('@inertiajs/vue3') || node.has('@inertiajs/inertia')
        ? 'Inertia'
        : php.has('livewire/livewire')
          ? 'Livewire'
          : null;

    const bundler = next
        ? 'Next.js'
        : node.has('vite') || files.exists('vite.config.js') || files.exists('vite.config.ts')
          ? 'Vite'
          : node.has('laravel-mix') || files.exists('webpack.mix.js')
            ? 'Laravel Mix'
            : node.has('react-scripts')
              ? 'Create React App'
              : node.has('webpack')
                ? 'Webpack'
                : null;

    const css = [];
    if (node.has('tailwindcss')) css.push(label('Tailwind CSS', node.version('tailwindcss')));
    if (node.has('bootstrap') || php.has('twbs/bootstrap')) css.push(label('Bootstrap', node.version('bootstrap')));
    if (node.has('sass') || node.has('node-sass')) css.push('SCSS');
    if (frontend?.framework === 'Next.js' && files.find('.', /\.module\.(css|scss)$/, 4, 1).length) css.push('CSS Modules');
    if (node.has('styled-components')) css.push('styled-components');

    const hasTs = node.has('typescript') || files.exists('tsconfig.json');
    const language = pkg ? (hasTs ? 'TypeScript' : 'JavaScript') : null;
    const python = ['Django', 'FastAPI', 'Flask', 'Python'].includes(backend?.framework ?? '');
    const languages = [composer || backend?.framework === 'PHP (no framework)' ? 'PHP' : null, python ? 'Python' : null, language].filter(/** @returns {x is string} */ (x) => x !== null);
    if (files.find('resources/views', /\.blade\.php$/, 3, 1).length) languages.push('Blade');

    const packageManager = files.exists('pnpm-lock.yaml')
        ? 'pnpm'
        : files.exists('yarn.lock')
          ? 'yarn'
          : files.exists('bun.lockb') || files.exists('bun.lock')
            ? 'bun'
            : pkg
              ? 'npm'
              : null;

    const nodeVersion = (files.read('.nvmrc') ?? files.read('.node-version'))?.trim() || pkg?.engines?.node || null;

    const database = detectDatabase(files, shape ?? exampleShape, node);

    const tests = [];
    if (php.has('pestphp/pest')) tests.push('Pest');
    if (php.has('phpunit/phpunit') || files.exists('phpunit.xml') || files.exists('phpunit.xml.dist')) tests.push('PHPUnit');
    if (node.has('vitest')) tests.push('Vitest');
    if (node.has('jest') || node.has('react-scripts')) tests.push('Jest');
    if (python && (files.exists('pytest.ini') || /pytest/i.test(files.read('requirements.txt') ?? ''))) tests.push('pytest');

    const e2e = [];
    if (node.has('@playwright/test') || node.has('playwright')) e2e.push('Playwright');
    if (node.has('cypress')) e2e.push('Cypress');
    if (php.has('laravel/dusk')) e2e.push('Laravel Dusk');

    const staticAnalysis = [];
    if (php.has('larastan/larastan') || php.has('nunomaduro/larastan')) staticAnalysis.push('Larastan');
    else if (php.has('phpstan/phpstan')) staticAnalysis.push('PHPStan');
    if (php.has('vimeo/psalm')) staticAnalysis.push('Psalm');
    if (node.has('eslint') || pkg?.eslintConfig) staticAnalysis.push('ESLint');
    if (hasTs) staticAnalysis.push('TypeScript');

    const formatters = [];
    if (php.has('laravel/pint')) formatters.push('Laravel Pint');
    if (php.has('friendsofphp/php-cs-fixer')) formatters.push('PHP-CS-Fixer');
    if (node.has('prettier')) formatters.push('Prettier');
    if (node.has('@biomejs/biome')) formatters.push('Biome');

    const auth = [];
    if (php.has('laravel/sanctum')) auth.push('Laravel Sanctum');
    if (php.has('laravel/passport')) auth.push('Laravel Passport');
    if (php.has('laravel/fortify')) auth.push('Laravel Fortify');
    if (php.has('laravel/breeze')) auth.push('Laravel Breeze');
    if (php.has('laravel/jetstream')) auth.push('Laravel Jetstream');
    if (php.has('laravel/ui')) auth.push('Laravel UI');
    if (php.has('tymon/jwt-auth') || php.has('php-open-source-saver/jwt-auth')) auth.push('JWT Auth');
    if (backend?.framework === 'Laravel' && auth.length === 0) auth.push('Laravel session');
    if (node.has('next-auth')) auth.push('NextAuth');
    if (node.has('@auth/core') || node.has('@auth/nextjs')) auth.push('Auth.js');
    if (node.has('@supabase/supabase-js') || node.has('@supabase/ssr')) auth.push('Supabase Auth');
    if (node.has('@clerk/nextjs') || node.has('@clerk/clerk-react')) auth.push('Clerk');

    const payments = [];
    if (php.has('stripe/stripe-php') || php.has('laravel/cashier') || node.has('stripe') || node.has('@stripe/stripe-js')) payments.push('Stripe');
    if (php.has('luigel/laravel-paymongo') || node.has('paymongo') || mentions(shape ?? exampleShape, 'PAYMONGO')) payments.push('PayMongo');
    if (php.has('srmklive/paypal') || node.has('@paypal/react-paypal-js') || mentions(shape ?? exampleShape, 'PAYPAL')) payments.push('PayPal');
    if (mentions(shape ?? exampleShape, 'XENDIT')) payments.push('Xendit');

    const envPrefixes = ['NEXT_PUBLIC_', 'VITE_', 'MIX_', 'REACT_APP_'].filter((prefix) =>
        Object.keys(shape ?? exampleShape ?? {}).some((k) => k.startsWith(prefix)),
    );

    const docker = [];
    if (files.exists('Dockerfile')) docker.push('Dockerfile');
    if (files.exists('docker-compose.yml') || files.exists('docker-compose.yaml') || files.exists('compose.yaml')) docker.push('Docker Compose');
    if (php.has('laravel/sail')) docker.push('Laravel Sail');

    const ci = [];
    if (files.isDir('.github/workflows')) ci.push('GitHub Actions');
    if (files.exists('.gitlab-ci.yml')) ci.push('GitLab CI');
    if (files.exists('bitbucket-pipelines.yml')) ci.push('Bitbucket Pipelines');
    if (files.exists('azure-pipelines.yml')) ci.push('Azure Pipelines');

    const webhookRoutes = detectWebhookRoutes(files);

    if (shape?.APP_DEBUG === 'true' && shape?.APP_ENV === 'production') warnings.push('APP_DEBUG is true while APP_ENV is production.');
    if (backend?.framework === 'Laravel' && backend.version && majorOf(backend.version) !== null && /** @type {number} */ (majorOf(backend.version)) < 9) {
        warnings.push(`Laravel ${majorOf(backend.version)} is a legacy release — commands and conventions are chosen for this version, not for current Laravel.`);
    }
    if (frontend?.framework === 'Next.js' && frontend.router === 'both') warnings.push('Next.js uses both the App Router and the Pages Router.');
    if (pkg && !files.isDir('node_modules')) warnings.push('node_modules is missing — run the package install before builds or frontend tests.');
    if (composer && !files.isDir('vendor')) warnings.push('vendor is missing — run composer install before PHP tests.');

    return {
        name: composer?.name?.split('/').pop() || pkg?.name || folderName,
        languages,
        backend,
        frontend,
        bridge,
        bundler,
        css,
        language,
        packageManager,
        node: nodeVersion,
        database,
        tests,
        e2e,
        staticAnalysis,
        formatters,
        auth,
        payments,
        webhookRoutes,
        envPrefixes,
        docker,
        ci,
        designSystem: detectDesignSystem(files),
        commands: suggestCommands({ files, php, pkg, backend, packageManager, tests, e2e, staticAnalysis, formatters }),
        envShape: shape,
        warnings,
    };
}

/**
 * @param {any} composer
 * @param {any} lock
 */
function phpPackages(composer, lock) {
    /** @type {Map<string, string>} */
    const locked = new Map();

    for (const p of [...(lock?.packages ?? []), ...(lock?.['packages-dev'] ?? [])]) {
        if (p?.name) locked.set(p.name.toLowerCase(), String(p.version ?? '').replace(/^v/, ''));
    }

    /** @type {Record<string, string>} */
    const required = { ...(composer?.require ?? {}), ...(composer?.['require-dev'] ?? {}) };
    const lower = Object.fromEntries(Object.entries(required).map(([k, v]) => [k.toLowerCase(), String(v)]));

    return {
        /** @param {string} name */
        has: (name) => name in lower || locked.has(name),
        /** @param {string} name */
        version: (name) => locked.get(name) ?? null,
        /** @param {string} name */
        constraint: (name) => lower[name] ?? null,
    };
}

/**
 * Exact versions from node_modules or the lockfile; the package.json range otherwise.
 * @param {ProjectFiles} files
 * @param {any} pkg
 */
function nodePackages(files, pkg) {
    /** @type {Record<string, string>} */
    const declared = { ...(pkg?.dependencies ?? {}), ...(pkg?.devDependencies ?? {}) };
    const lock = files.json('package-lock.json');

    /** @param {string} name */
    const version = (name) => {
        if (!(name in declared)) return null;

        const installed = files.json(`node_modules/${name}/package.json`);
        if (installed?.version) return String(installed.version);

        const locked = lock?.packages?.[`node_modules/${name}`]?.version ?? lock?.dependencies?.[name]?.version;
        if (locked) return String(locked);

        return String(declared[name]).replace(/^[\^~>=<\s]+/, '') || null;
    };

    return {
        /** @param {string} name */
        has: (name) => name in declared,
        version,
    };
}

/**
 * @param {ProjectFiles} files
 * @param {any} composer
 * @param {ReturnType<typeof phpPackages>} php
 */
function detectBackend(files, composer, php) {
    if (!composer) return detectBackendWithoutComposer(files);

    const phpReq = php.constraint('php');

    if (php.has('laravel/framework')) {
        const structure = files.exists('bootstrap/app.php') && /->withRouting\(/.test(files.read('bootstrap/app.php') ?? '')
            ? 'Laravel 11+ (bootstrap/app.php)'
            : files.exists('app/Http/Kernel.php')
              ? 'HTTP Kernel (Laravel ≤ 10)'
              : null;

        return { framework: 'Laravel', version: php.version('laravel/framework'), constraint: php.constraint('laravel/framework'), php: phpReq, structure: structure ?? undefined };
    }

    if (php.has('laravel/lumen-framework')) return { framework: 'Lumen', version: php.version('laravel/lumen-framework'), constraint: php.constraint('laravel/lumen-framework'), php: phpReq };
    if (php.has('symfony/framework-bundle')) return { framework: 'Symfony', version: php.version('symfony/framework-bundle'), constraint: php.constraint('symfony/framework-bundle'), php: phpReq };
    if (php.has('codeigniter4/framework')) return { framework: 'CodeIgniter', version: php.version('codeigniter4/framework'), constraint: null, php: phpReq };

    return { framework: 'PHP', version: null, constraint: null, php: phpReq };
}

/**
 * Projects without composer.json: plain PHP apps (common under XAMPP) and
 * Python services. Reported as they are, so nothing assumes Laravel.
 *
 * @param {ProjectFiles} files
 * @returns {StackProfile['backend']}
 */
function detectBackendWithoutComposer(files) {
    const requirements = (files.read('requirements.txt') ?? '') + (files.read('pyproject.toml') ?? '');

    if (files.exists('manage.py') || /^django\b/im.test(requirements)) {
        return { framework: 'Django', version: /^django\s*[=~><]=*\s*([\d.]+)/im.exec(requirements)?.[1] ?? null, constraint: null, php: null };
    }
    if (/^fastapi\b/im.test(requirements)) return { framework: 'FastAPI', version: null, constraint: null, php: null };
    if (/^flask\b/im.test(requirements)) return { framework: 'Flask', version: null, constraint: null, php: null };
    if (requirements) return { framework: 'Python', version: null, constraint: null, php: null };

    if (files.find('.', /\.php$/, 2, 1).length) {
        return { framework: 'PHP (no framework)', version: null, constraint: null, php: null };
    }

    return null;
}

/**
 * @param {ProjectFiles} files
 * @param {ReturnType<typeof nodePackages>} node
 * @param {string | null} next
 */
function detectFrontend(files, node, next) {
    if (next) {
        const app = files.isDir('app') || files.isDir('src/app');
        const pages = files.isDir('pages') || files.isDir('src/pages');
        const router = app && pages ? 'both' : app ? 'app' : pages ? 'pages' : null;

        return { framework: 'Next.js', version: next, router, meta: `React ${node.version('react') ?? '?'}` };
    }

    if (node.has('react')) {
        const router = node.has('react-router-dom') || node.has('react-router') ? 'React Router' : null;

        return { framework: 'React', version: node.version('react'), router, meta: null };
    }

    if (node.has('vue')) {
        const version = node.version('vue');

        return { framework: version?.startsWith('2') ? 'Vue 2' : 'Vue 3', version, router: node.has('vue-router') ? 'Vue Router' : null, meta: node.has('nuxt') ? `Nuxt ${node.version('nuxt')}` : null };
    }

    if (node.has('svelte')) return { framework: 'Svelte', version: node.version('svelte'), router: null, meta: null };
    if (node.has('alpinejs')) return { framework: 'Alpine.js', version: node.version('alpinejs'), router: null, meta: null };
    if (node.has('jquery')) return { framework: 'jQuery', version: node.version('jquery'), router: null, meta: null };
    if (files.find('resources/views', /\.blade\.php$/, 3, 1).length) return { framework: 'Blade', version: null, router: null, meta: null };

    return null;
}

/**
 * @param {ProjectFiles} files
 * @param {Record<string, string> | null} shape
 * @param {ReturnType<typeof nodePackages>} node
 */
function detectDatabase(files, shape, node) {
    const names = { mysql: 'MySQL', mariadb: 'MariaDB', pgsql: 'PostgreSQL', postgres: 'PostgreSQL', postgresql: 'PostgreSQL', sqlite: 'SQLite', sqlsrv: 'SQL Server', mongodb: 'MongoDB' };
    const conn = shape?.DB_CONNECTION?.toLowerCase();

    if (conn && conn in names) return names[/** @type {keyof typeof names} */ (conn)];

    const prisma = files.read('prisma/schema.prisma');
    const provider = prisma && /provider\s*=\s*"(\w+)"/.exec(prisma.slice(prisma.indexOf('datasource')))?.[1];
    if (provider && provider in names) return names[/** @type {keyof typeof names} */ (provider)];

    // Plain PHP apps: look at how the code connects.
    for (const rel of files.find('.', /\.php$/, 3, 60)) {
        const text = files.read(rel, 256 * 1024) ?? '';
        if (/mysqli_connect|new\s+mysqli|mysql:host=/i.test(text)) return 'MySQL';
        if (/pgsql:host=|pg_connect/i.test(text)) return 'PostgreSQL';
    }

    if (node.has('mongoose')) return 'MongoDB';
    if (node.has('pg')) return 'PostgreSQL';
    if (node.has('mysql2')) return 'MySQL';

    return null;
}

/**
 * @param {ProjectFiles} files
 */
function detectWebhookRoutes(files) {
    /** @type {string[]} */
    const routes = [];

    for (const rel of files.find('routes', /\.php$/, 2, 40)) {
        const text = files.read(rel) ?? '';

        for (const m of text.matchAll(/Route::(get|post|put|patch|delete|any|match)\(\s*['"]([^'"]*webhook[^'"]*)['"]/gi)) {
            routes.push(`${m[1].toUpperCase()} /${m[2].replace(/^\//, '')}`);
        }
    }

    for (const rel of files.find('.', /(app|pages)[\\/](.*[\\/])?(api[\\/])?.*webhook.*[\\/](route|index)\.(t|j)sx?$/i, 7, 20)) {
        routes.push(rel);
    }

    return [...new Set(routes)].slice(0, 20);
}

/**
 * @param {ProjectFiles} files
 */
function detectDesignSystem(files) {
    const tailwindConfig = ['tailwind.config.js', 'tailwind.config.ts', 'tailwind.config.cjs', 'tailwind.config.mjs'].find((f) => files.exists(f)) ?? null;
    const componentDirs = ['resources/js/components', 'resources/js/Components', 'resources/views/components', 'src/components', 'components', 'app/components', 'src/components/ui', 'resources/js/components/ui']
        .filter((d) => files.isDir(d));
    const css = files.read('resources/css/app.css') ?? files.read('src/index.css') ?? files.read('app/globals.css') ?? files.read('src/app/globals.css') ?? '';

    return { tailwindConfig, componentDirs, cssVariables: /--[\w-]+\s*:/.test(css) };
}

/**
 * Commands that fit THIS project's versions (Plan §19, §84). Only suggestions —
 * executing them arrives with the command runner (Phase 7).
 *
 * @param {{ files: ProjectFiles, php: ReturnType<typeof phpPackages>, pkg: any, backend: StackProfile['backend'], packageManager: string | null, tests: string[], e2e: string[], staticAnalysis: string[], formatters: string[] }} ctx
 */
function suggestCommands({ files, php, pkg, backend, packageManager, tests, e2e, staticAnalysis, formatters }) {
    /** @type {{ label: string, command: string }[]} */
    const out = [];
    const run = packageManager === 'yarn' ? 'yarn' : packageManager === 'pnpm' ? 'pnpm' : packageManager === 'bun' ? 'bun run' : 'npm run';
    const scripts = pkg?.scripts ?? {};

    if (files.exists('composer.json')) out.push({ label: 'Install PHP dependencies', command: 'composer install' });
    if (files.exists('requirements.txt')) out.push({ label: 'Install Python dependencies', command: 'pip install -r requirements.txt' });
    if (backend?.framework === 'Django') out.push({ label: 'Python tests', command: 'python manage.py test' });
    else if (tests.includes('pytest')) out.push({ label: 'Python tests', command: 'pytest' });
    if (pkg) out.push({ label: 'Install JS dependencies', command: packageManager === 'npm' && files.exists('package-lock.json') ? 'npm ci' : `${packageManager ?? 'npm'} install` });

    if (tests.includes('Pest')) out.push({ label: 'PHP tests', command: 'vendor/bin/pest' });
    else if (backend?.framework === 'Laravel' && php.has('nunomaduro/collision')) out.push({ label: 'PHP tests', command: 'php artisan test' });
    else if (tests.includes('PHPUnit')) out.push({ label: 'PHP tests', command: 'vendor/bin/phpunit' });

    if (staticAnalysis.includes('Larastan') || staticAnalysis.includes('PHPStan')) out.push({ label: 'PHP static analysis', command: 'vendor/bin/phpstan analyse' });
    if (formatters.includes('Laravel Pint')) out.push({ label: 'PHP formatter', command: 'vendor/bin/pint' });

    if (scripts.lint) out.push({ label: 'Lint', command: `${run} lint` });
    if (scripts.test) out.push({ label: 'JS tests', command: `${run} test` });
    if (scripts['type-check'] || scripts.typecheck) out.push({ label: 'Type check', command: `${run} ${scripts['type-check'] ? 'type-check' : 'typecheck'}` });
    else if (staticAnalysis.includes('TypeScript')) out.push({ label: 'Type check', command: 'npx tsc --noEmit' });

    // Laravel Mix projects build with "production"; everything else with "build".
    if (scripts.build) out.push({ label: 'Frontend build', command: `${run} build` });
    else if (scripts.production || scripts.prod) out.push({ label: 'Frontend build', command: `${run} ${scripts.production ? 'production' : 'prod'}` });

    if (e2e.includes('Playwright')) out.push({ label: 'E2E tests', command: 'npx playwright test' });
    if (e2e.includes('Cypress')) out.push({ label: 'E2E tests', command: 'npx cypress run' });
    if (e2e.includes('Laravel Dusk')) out.push({ label: 'Browser tests', command: 'php artisan dusk' });

    return out;
}

/**
 * @param {string | null} name
 * @param {string | null} version
 */
function label(name, version) {
    return version ? `${name} ${version.split('.').slice(0, 2).join('.')}` : /** @type {string} */ (name);
}

/**
 * @param {Record<string, string> | null} shape
 * @param {string} needle
 */
function mentions(shape, needle) {
    return Object.keys(shape ?? {}).some((k) => k.includes(needle) && shape?.[k] !== '<empty>');
}

/**
 * @param {string} version
 * @returns {number | null}
 */
export function majorOf(version) {
    const m = /(\d+)/.exec(version);

    return m ? Number(m[1]) : null;
}
