#!/usr/bin/env node
// Builds engine-api.json (the identifier allowlist for scene code) from the engine sources.
import fs from 'node:fs'; import path from 'node:path'; import { fileURLToPath, pathToFileURL } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const acorn = await import(pathToFileURL(path.join(here, '../../node_modules/acorn/dist/acorn.mjs')).href);
const dir = process.argv[2] || path.join(here, '../kikitoons-compat');
const internal = new Set(['hash', 'wallGrad', '__cam', 'drawArm', 'drawSuitArm', 'drawCap', 'drawHair', 'drawMouth', 'wrap']);
const names = new Set();
for (const f of ['core.js', 'bg.js', 'engine.js']) {
  const ast = acorn.parse(fs.readFileSync(path.join(dir, f), 'utf8'), { ecmaVersion: 2022 });
  for (const n of ast.body) {
    if (n.type === 'FunctionDeclaration') names.add(n.id.name);
    if (n.type === 'VariableDeclaration') n.declarations.forEach(d => d.id.type === 'Identifier' && names.add(d.id.name));
  }
}
const globals = [...names].filter(n => !internal.has(n)).sort();
fs.writeFileSync(path.join(here, 'engine-api.json'), JSON.stringify({ source: dir, globals }, null, 2));
console.log(globals.length + ' engine globals: ' + globals.join(', '));
