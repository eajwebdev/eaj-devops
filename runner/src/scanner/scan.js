// @ts-check
import path from 'node:path';
import { gitState } from '../git-state.js';
import { ProjectFiles } from './files.js';
import { detectStack } from './detect.js';

/**
 * Scan a project folder: stack profile + Git state (Plan §12, §18).
 * Read-only — nothing in the folder is changed.
 *
 * @param {string} folder already validated against the allowed roots
 * @param {{ workspacesRoot: string, progress?: (message: string) => Promise<void> | void }} options
 */
export async function scanProject(folder, { workspacesRoot, progress = () => {} }) {
    const files = new ProjectFiles(folder);

    await progress('Reading project manifests…');
    const stack = detectStack(files, path.basename(folder));

    const framework = stack.backend?.framework === 'Laravel' ? `Laravel ${stack.backend.version ?? stack.backend.constraint ?? ''}`.trim() : stack.frontend?.framework;
    await progress(framework ? `Detected ${framework}` : 'No known framework detected');

    await progress('Checking Git state…');
    const git = await gitState(folder, workspacesRoot);

    const warnings = [...stack.warnings];

    if (!git.enabled) {
        warnings.unshift('Not a Git repository. Safe AI changes need version control — initialize Git from the Studio first.');
    } else {
        if (!git.hasCommits) warnings.unshift('The repository has no commits yet. Create a baseline commit before AI work can start.');
        if (git.envTracked) warnings.unshift('.env is tracked by Git — secrets may be in the repository history.');
        if (git.modified + git.untracked > 0) {
            warnings.push(`${git.modified + git.untracked} uncommitted change(s). They stay untouched; task workspaces start from the last commit.`);
        }
    }

    return { stack: { ...stack, warnings: undefined }, git, warnings };
}
