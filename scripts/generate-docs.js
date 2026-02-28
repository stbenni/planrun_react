#!/usr/bin/env node
/**
 * Генератор полной документации проекта PlanRun
 * Извлекает функции, компоненты и классы из исходников
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const ROOT = path.join(__dirname, '..');
const DOCS = path.join(ROOT, 'docs');

const EXCLUDE = ['node_modules', 'vendor', 'dist', '.git', 'build'];

function extractJsFunctions(content, filePath) {
  const items = [];
  // function name(...) or const name = (...) =>
  const funcRegex = /(?:function\s+(\w+)\s*\(|(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s*)?(?:\([^)]*\)\s*=>|function))/g;
  // export default function/const
  const exportDefaultRegex = /export\s+default\s+(?:function\s+)?(\w+)|export\s+default\s+(\w+)/;
  // React components: function Name( or const Name = (
  const componentRegex = /(?:function\s+|const\s+)([A-Z][a-zA-Z0-9]*)\s*[=(]/g;
  // export { ... }
  const exportNamedRegex = /export\s+{\s*([^}]+)\s*}/g;

  let m;
  while ((m = funcRegex.exec(content)) !== null) {
    const name = m[1] || m[2];
    if (name && !['catch', 'then', 'if', 'for'].includes(name)) {
      items.push({ type: 'function', name });
    }
  }
  while ((m = componentRegex.exec(content)) !== null) {
    const name = m[1];
    if (name && !items.some(i => i.name === name)) {
      items.push({ type: 'component', name });
    }
  }
  return items;
}

function extractPhpFunctions(content) {
  const items = [];
  // function name(...) or public/protected/private function name
  const funcRegex = /(?:^\s*(?:public|protected|private|static)\s+)?function\s+(\w+)\s*\(/gm;
  let m;
  while ((m = funcRegex.exec(content)) !== null) {
    items.push({ type: 'function', name: m[1] });
  }
  // class Name
  const classRegex = /class\s+(\w+)/g;
  while ((m = classRegex.exec(content)) !== null) {
    items.push({ type: 'class', name: m[1] });
  }
  return items;
}

function extractFromFile(filePath) {
  const ext = path.extname(filePath);
  if (!['.js', '.jsx', '.php'].includes(ext)) return null;
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    const relative = path.relative(ROOT, filePath);
    if (ext === '.php') {
      return { path: relative, items: extractPhpFunctions(content) };
    }
    return { path: relative, items: extractJsFunctions(content, filePath) };
  } catch (e) {
    return null;
  }
}

function walkDir(dir, files = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const e of entries) {
    const full = path.join(dir, e.name);
    if (EXCLUDE.some(x => full.includes(x))) continue;
    if (e.isDirectory()) {
      walkDir(full, files);
    } else if (e.isFile() && /\.(js|jsx|php)$/.test(e.name)) {
      files.push(full);
    }
  }
  return files;
}

function main() {
  const srcFiles = walkDir(path.join(ROOT, 'src'));
  const apiFiles = walkDir(path.join(ROOT, 'api'));
  const backendFiles = walkDir(path.join(ROOT, 'planrun-backend'));

  const all = [...srcFiles, ...apiFiles, ...backendFiles]
    .filter(f => !f.includes('vendor') && !f.includes('node_modules'));

  const data = { frontend: [], api: [], backend: [] };

  for (const f of all) {
    const ext = extractFromFile(f);
    if (ext) {
      const rel = f.replace(ROOT + path.sep, '').replace(/\\/g, '/');
      if (rel.startsWith('src/')) data.frontend.push({ ...ext, path: rel });
      else if (rel.startsWith('api/')) data.api.push({ ...ext, path: rel });
      else if (rel.startsWith('planrun-backend/')) data.backend.push({ ...ext, path: rel });
    }
  }

  fs.writeFileSync(path.join(DOCS, 'api-reference.json'), JSON.stringify(data, null, 2));
  console.log('Generated api-reference.json with', data.frontend.length + data.api.length + data.backend.length, 'files');
}

main();
