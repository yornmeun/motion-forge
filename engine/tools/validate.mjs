#!/usr/bin/env node
/*
 * Static validator for AI-generated scene code (untrusted).
 * stdin: {"code": "...", "allow": ["extra", "globals"]}
 * stdout: {"ok": bool, "errors": [..], "warnings": [..], "functions": [..]}
 *
 * Contract: the code contains only top-level function declarations, one of them `scene(ctx, t)`.
 * Only engine API names, local declarations and a few safe built-ins may be referenced.
 * This is defence-in-depth; the render sandbox (CSP, no network, timeouts) is the runtime boundary.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const acorn = await import(path.join(here, '../../node_modules/acorn/dist/acorn.mjs'));
const walk = await import(path.join(here, '../../node_modules/acorn-walk/dist/walk.mjs'));

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const code = String(input.code ?? '');
const errors = [], warnings = [];

const ENGINE = JSON.parse(fs.readFileSync(path.join(here, 'engine-api.json'), 'utf8')).globals;
const BUILTINS = ['Math', 'Number', 'String', 'Boolean', 'Array', 'Infinity', 'NaN', 'undefined', 'isFinite', 'isNaN', 'parseFloat', 'parseInt', 'Path2D', 'STORY', 'VO_ENV', 'CAPS'];
const ALLOWED = new Set([...ENGINE, ...BUILTINS, ...(input.allow || [])]);
const FORBIDDEN_PROPS = new Set(['constructor', '__proto__', 'prototype', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__', 'caller', 'callee', 'arguments', 'ownerDocument', 'defaultView', 'getRootNode', 'canvas', 'toDataURL', 'getImageData', 'putImageData', 'transferControlToOffscreen']);
const NEW_OK = new Set(['Path2D', 'Array']);

if (code.length > 60000) errors.push('code exceeds 60 KB');
let ast;
try { ast = acorn.parse(code, { ecmaVersion: 2022, sourceType: 'script', locations: true }); }
catch (e) { emit({ ok: false, errors: [`syntax error: ${e.message}`], warnings, functions: [] }); }

const functions = [];
for (const node of ast.body) {
  if (node.type !== 'FunctionDeclaration') { errors.push(`line ${node.loc.start.line}: only top-level function declarations are allowed (found ${node.type})`); continue; }
  if (node.async || node.generator) errors.push(`line ${node.loc.start.line}: async/generator functions are not allowed`);
  functions.push(node.id.name);
}
const sceneFn = ast.body.find(n => n.type === 'FunctionDeclaration' && n.id.name === 'scene');
if (!sceneFn) errors.push('missing `function scene(ctx, t) { ... }`');
else if (sceneFn.params.length < 2) errors.push('`scene` must take (ctx, t)');

// declared names (flat, conservative)
const declared = new Set(functions);
walk.full(ast, n => {
  if (n.type === 'VariableDeclarator') collectPattern(n.id);
  if (n.type === 'FunctionDeclaration' || n.type === 'FunctionExpression' || n.type === 'ArrowFunctionExpression') { if (n.id) declared.add(n.id.name); n.params.forEach(collectPattern); }
  if (n.type === 'CatchClause' && n.param) collectPattern(n.param);
});
function collectPattern(p) {
  if (!p) return;
  if (p.type === 'Identifier') declared.add(p.name);
  else if (p.type === 'ObjectPattern') p.properties.forEach(q => collectPattern(q.value || q.argument));
  else if (p.type === 'ArrayPattern') p.elements.forEach(collectPattern);
  else if (p.type === 'AssignmentPattern') collectPattern(p.left);
  else if (p.type === 'RestElement') collectPattern(p.argument);
}

const at = n => `line ${n.loc.start.line}`;
walk.fullAncestor(ast, (n, _s, anc) => {
  switch (n.type) {
    case 'ThisExpression': errors.push(`${at(n)}: \`this\` is not allowed`); break;
    case 'WithStatement': case 'DebuggerStatement': case 'ImportExpression': case 'MetaProperty': case 'ClassDeclaration': case 'ClassExpression': case 'AwaitExpression': case 'YieldExpression': case 'TaggedTemplateExpression':
      errors.push(`${at(n)}: ${n.type} is not allowed`); break;
    case 'NewExpression':
      if (n.callee.type !== 'Identifier' || !NEW_OK.has(n.callee.name)) errors.push(`${at(n)}: \`new\` is only allowed for ${[...NEW_OK].join(', ')}`); break;
    case 'MemberExpression': {
      const p = n.property;
      if (!n.computed && p.type === 'Identifier' && FORBIDDEN_PROPS.has(p.name)) errors.push(`${at(n)}: access to .${p.name} is not allowed`);
      if (n.computed) {
        if (p.type === 'Literal' && typeof p.value === 'string' && FORBIDDEN_PROPS.has(p.value)) errors.push(`${at(n)}: access to ["${p.value}"] is not allowed`);
        if (p.type === 'TemplateLiteral' || (p.type === 'BinaryExpression' && containsString(p))) errors.push(`${at(n)}: computed string property keys are not allowed`);
      }
      break;
    }
    case 'Identifier': {
      const parent = anc[anc.length - 2];
      if (!parent) break;
      if (parent.type === 'MemberExpression' && parent.property === n && !parent.computed) break;
      if ((parent.type === 'Property' || parent.type === 'MethodDefinition') && parent.key === n && !parent.computed) break;
      if (parent.type === 'LabeledStatement' || parent.type === 'BreakStatement' || parent.type === 'ContinueStatement') break;
      if (declared.has(n.name) || ALLOWED.has(n.name)) break;
      errors.push(`${at(n)}: unknown or forbidden identifier \`${n.name}\``);
      break;
    }
    case 'AssignmentExpression': {
      // never let scene code overwrite engine globals
      if (n.left.type === 'Identifier' && ALLOWED.has(n.left.name) && !declared.has(n.left.name)) errors.push(`${at(n)}: cannot assign to engine global \`${n.left.name}\``);
      if (n.left.type === 'MemberExpression' && n.left.object.type === 'Identifier' && ['STORY', 'Math', 'E', 'ARMS', 'BACKGROUNDS', 'CAPS'].includes(n.left.object.name)) errors.push(`${at(n)}: cannot mutate ${n.left.object.name}`);
      break;
    }
    case 'WhileStatement': case 'DoWhileStatement': warnings.push(`${at(n)}: while loops must terminate; the renderer enforces a per-frame timeout`); break;
  }
});
function containsString(node) { let s = false; walk.full(node, x => { if (x.type === 'Literal' && typeof x.value === 'string') s = true; }); return s; }

emit({ ok: errors.length === 0, errors: [...new Set(errors)].slice(0, 40), warnings: [...new Set(warnings)], functions });
function emit(o) { process.stdout.write(JSON.stringify(o)); process.exit(0); }
