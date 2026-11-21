/**
 * Generates the PWA icons from the application mark.
 *
 * The icons are committed to the repository so a fresh clone can build the
 * application without running this script.
 */
import { mkdir, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import sharp from 'sharp'

const here = dirname(fileURLToPath(import.meta.url))
const publicDir = resolve(here, '../public')

const SIZES = [192, 512]

/**
 * The application mark: a rounded square with a speech bubble.
 *
 * The SVG is drawn here instead of being read from disk so the icons and the
 * favicon cannot drift apart.
 */
function mark(size) {
  const radius = size * 0.22

  return `
    <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
      <rect width="${size}" height="${size}" rx="${radius}" fill="#5865f2" />
      <path
        d="M ${size * 0.31} ${size * 0.34}
           c 0 -${size * 0.05} ${size * 0.04} -${size * 0.09} ${size * 0.09} -${size * 0.09}
           h ${size * 0.2}
           c ${size * 0.05} 0 ${size * 0.09} ${size * 0.04} ${size * 0.09} ${size * 0.09}
           v ${size * 0.13}
           c 0 ${size * 0.05} -${size * 0.04} ${size * 0.09} -${size * 0.09} ${size * 0.09}
           h -${size * 0.1}
           l -${size * 0.1} ${size * 0.1}
           v -${size * 0.1}
           h -${size * 0.01}
           c -${size * 0.03} 0 -${size * 0.05} -${size * 0.02} -${size * 0.05} -${size * 0.05}
           z"
        fill="#ffffff"
      />
    </svg>
  `
}

await mkdir(publicDir, { recursive: true })

for (const size of SIZES) {
  const buffer = await sharp(Buffer.from(mark(size))).png().toBuffer()

  await writeFile(resolve(publicDir, `pwa-${size}x${size}.png`), buffer)

  process.stdout.write(`pwa-${size}x${size}.png generado\n`)
}
