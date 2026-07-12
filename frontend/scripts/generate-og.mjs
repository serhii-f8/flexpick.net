import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const svg = `<svg width="1200" height="630" xmlns="http://www.w3.org/2000/svg">
  <rect width="1200" height="630" fill="#0d0c0a"/>
  <rect x="0" y="622" width="1200" height="8" fill="#d4a853"/>
  <text x="80" y="270" font-family="Helvetica, Arial, sans-serif" font-size="76" font-weight="800" fill="#f5f5f0">Flex<tspan fill="#d4a853">Pick</tspan></text>
  <text x="80" y="366" font-family="Helvetica, Arial, sans-serif" font-size="42" fill="#e8e6de">We rescue AI-built codebases.</text>
  <text x="80" y="440" font-family="Helvetica, Arial, sans-serif" font-size="26" fill="#8a877d">Free codebase audit · flexpick.net</text>
</svg>`;

const outDir = fileURLToPath(new URL('../src/assets/images/', import.meta.url));
await mkdir(outDir, { recursive: true });
await sharp(Buffer.from(svg))
  .png()
  .toFile(outDir + 'default.png');
console.log('Wrote src/assets/images/default.png');
