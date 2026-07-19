import { readdir, readFile } from 'node:fs/promises';
import { dirname, extname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoots = ['app', 'src'].map((directory) => join(frontendRoot, directory));
const sourceExtensions = new Set(['.js', '.jsx', '.mjs', '.ts', '.tsx']);
const violations = [];

for (const sourceRoot of sourceRoots) {
  for (const file of await sourceFiles(sourceRoot)) {
    inspectSource(file, await readFile(file, 'utf8'));
  }
}

const apiClientPath = join(frontendRoot, 'src', 'lib', 'apiClient.ts');
const apiClient = await readFile(apiClientPath, 'utf8');
if (!/credentials\s*:\s*['"]include['"]/.test(apiClient)) {
  violations.push(`${displayPath(apiClientPath)}: web-API requests must use credentials: 'include'`);
}

const authContextPath = join(frontendRoot, 'src', 'features', 'auth', 'AuthContext.tsx');
const authContext = await readFile(authContextPath, 'utf8');
for (const requiredActivitySignal of ['keydown', 'pointerdown', 'touchstart', 'visibilitychange']) {
  if (!authContext.includes(`'${requiredActivitySignal}'`)) {
    violations.push(`${displayPath(authContextPath)}: authenticated session activity must include ${requiredActivitySignal}`);
  }
}
if (!/api\.post<void>\(['"]\/auth\/session\/touch['"]\)/.test(authContext)) {
  violations.push(`${displayPath(authContextPath)}: authenticated activity must refresh the server-side session`);
}
if (/setInterval\s*\([^)]*\/auth\/session\/touch/s.test(authContext)) {
  violations.push(`${displayPath(authContextPath)}: an idle browser tab may not keep a session alive automatically`);
}

if (violations.length > 0) {
  process.stderr.write(`Browser authentication source check failed:\n- ${violations.join('\n- ')}\n`);
  process.exitCode = 1;
} else {
  process.stdout.write('Browser authentication source check passed.\n');
}

async function sourceFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...await sourceFiles(path));
    } else if (sourceExtensions.has(extname(entry.name))) {
      files.push(path);
    }
  }

  return files;
}

function inspectSource(file, source) {
  const path = displayPath(file);
  const lines = source.split(/\r?\n/);

  lines.forEach((line, index) => {
    const lineNumber = index + 1;

    if (/\b(?:localStorage|sessionStorage)\.(?:getItem|setItem)\s*\([^)]*(?:auth|bearer|mfa|purpose|session|token|dis\.session\.)/i.test(line)) {
      violations.push(`${path}:${lineNumber}: authentication state may not be read from or written to browser storage`);
    }

    if (/(?:\bAuthorization\b\s*:|['"]Authorization['"]\s*:|\bBearer\s+\$?\{)/i.test(line)) {
      violations.push(`${path}:${lineNumber}: the web client may not construct an Authorization/Bearer header`);
    }
  });

  const normalizedPath = path.replaceAll('\\', '/').toLowerCase();
  if (normalizedPath.endsWith('src/features/auth/authcontext.tsx')) {
    const forbiddenAuthState = [
      /\[\s*token\s*,\s*setToken\s*\]/,
      /\bgetToken\s*:/,
      /\btoken\??\s*:\s*string\b/,
      /\bsetSession\s*:\s*\(\s*token\s*:/,
    ];

    for (const pattern of forbiddenAuthState) {
      if (pattern.test(source)) {
        violations.push(`${path}: browser-readable authentication tokens may not be held in React authentication state`);
      }
    }
  }

  if (normalizedPath.endsWith('src/features/registration/registerwizardpage.tsx')
    && /\bquery\.get\(\s*['"](?:email|token)['"]\s*\)/i.test(source)) {
    violations.push(`${path}: invitation credentials may only be read from the URL fragment, never from the query string`);
  }
}

function displayPath(file) {
  return relative(frontendRoot, file);
}
