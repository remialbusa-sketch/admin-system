/**
 * Removes the Vite dev-server marker file (`public/hot`).
 *
 * While `public/hot` exists, Laravel renders dev-server asset URLs
 * (e.g. http://[::1]:5173/...). Those only work on the machine running
 * `npm run dev` — over an ngrok tunnel every visitor's browser tries to
 * load them from its own localhost, so the frontend breaks.
 *
 * Running this before sharing the app through ngrok forces Laravel back
 * to the built assets in `public/build`, which the tunnel serves itself.
 */
import { existsSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const hotFile = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'hot');

if (existsSync(hotFile)) {
    rmSync(hotFile);
    console.log('Removed public/hot — Laravel will serve built assets.');
} else {
    console.log('No public/hot present — already serving built assets.');
}
