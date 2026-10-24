// Render Graphviz DOT files (or ready-made SVG files) to black-and-white PNG images.
//   node dot2png.js <input-dir> <output-dir>
// Every *.dot file in the input folder is laid out with Graphviz (WebAssembly build) and
// rasterised; every *.svg file is rasterised as is. Output: <name>.png and <name>.svg.
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
const { Graphviz } = require('@hpcc-js/wasm');

async function main() {
    const [inDir, outDir] = process.argv.slice(2);
    if (!inDir || !outDir) {
        console.error('usage: node dot2png.js <input-dir> <output-dir>');
        process.exit(2);
    }
    fs.mkdirSync(outDir, { recursive: true });
    const gv = await Graphviz.load();
    let n = 0;
    for (const file of fs.readdirSync(inDir).sort()) {
        const ext = path.extname(file).toLowerCase();
        if (ext !== '.dot' && ext !== '.svg') continue;
        const name = path.basename(file, ext);
        let svg;
        if (ext === '.dot') {
            const engine = /^\s*\/\/\s*engine:\s*(\w+)/m.exec(fs.readFileSync(path.join(inDir, file), 'utf8'));
            svg = gv.layout(fs.readFileSync(path.join(inDir, file), 'utf8'), 'svg', engine ? engine[1] : 'dot');
        } else {
            svg = fs.readFileSync(path.join(inDir, file), 'utf8');
        }
        fs.writeFileSync(path.join(outDir, name + '.svg'), svg);
        // 2x resolution on a white background for crisp printing
        await sharp(Buffer.from(svg), { density: 192 }).flatten({ background: '#ffffff' }).grayscale().png({ compressionLevel: 9 }).toFile(path.join(outDir, name + '.png'));
        n++;
        console.log('drew', name);
    }
    console.log(n + ' diagrams drawn');
}
main().catch((e) => { console.error(e); process.exit(1); });
