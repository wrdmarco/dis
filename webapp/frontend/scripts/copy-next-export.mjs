import { cp, rm } from 'node:fs/promises';
import { resolve } from 'node:path';

const outDirIndex = process.argv.indexOf('--outDir');
const outputDirectory = outDirIndex >= 0 && process.argv[outDirIndex + 1]
  ? process.argv[outDirIndex + 1]
  : 'dist';

const root = process.cwd();
const source = resolve(root, 'out');
const destination = resolve(root, outputDirectory);

await rm(destination, { recursive: true, force: true });
await cp(source, destination, { recursive: true });
