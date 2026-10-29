/**
 * PNG writer + tiny software rasteriser.
 *
 * Node cannot create a WebGL context here, so this renders the *real* skinned
 * mesh geometry (produced by the real GLTFLoader + the real retargeter + the
 * real controller) into a flat-shaded RGBA buffer and writes a PNG with zlib.
 * It is a verification tool: it lets the shapes and poses be inspected without
 * a browser.
 */
import zlib from 'node:zlib';

/* ------------------------------------------------------------------- PNG out */

const CRC_TABLE = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n += 1) {
    let c = n;
    for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i += 1) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeBuf = Buffer.from(type, 'ascii');
  const crcBuf = Buffer.alloc(4);
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([len, typeBuf, data, crcBuf]);
}

export function encodePNG(width, height, rgba) {
  const raw = Buffer.alloc((width * 4 + 1) * height);
  for (let y = 0; y < height; y += 1) {
    raw[y * (width * 4 + 1)] = 0; // filter: none
    rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  ihdr[10] = 0;
  ihdr[11] = 0;
  ihdr[12] = 0;
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

/* ------------------------------------------------------------------ rasteriser */

export class Canvas {
  constructor(width, height, background = [255, 255, 255]) {
    this.width = width;
    this.height = height;
    this.rgba = Buffer.alloc(width * height * 4);
    this.depth = new Float32Array(width * height).fill(Infinity);
    this.fill(background);
  }

  fill([r, g, b]) {
    for (let i = 0; i < this.width * this.height; i += 1) {
      this.rgba[i * 4] = r;
      this.rgba[i * 4 + 1] = g;
      this.rgba[i * 4 + 2] = b;
      this.rgba[i * 4 + 3] = 255;
    }
  }

  /** Fills a horizontal region of the depth buffer so the floor occludes properly. */
  floorY(pixelY, depth) {
    if (pixelY < 0 || pixelY >= this.height) return;
    const row = this.width * pixelY;
    for (let x = 0; x < this.width; x += 1) {
      if (this.depth[row + x] > depth) {
        this.depth[row + x] = depth;
        const i = (row + x) * 4;
        // very light grey floor grid so it is clear what "the ground" is
        const g = (x % 64 === 0 || pixelY % 64 === 0) ? 226 : 244;
        this.rgba[i] = g;
        this.rgba[i + 1] = g;
        this.rgba[i + 2] = g;
        this.rgba[i + 3] = 255;
      }
    }
  }

  triangle(a, b, c, color) {
    const minX = Math.max(0, Math.floor(Math.min(a[0], b[0], c[0])));
    const maxX = Math.min(this.width - 1, Math.ceil(Math.max(a[0], b[0], c[0])));
    const minY = Math.max(0, Math.floor(Math.min(a[1], b[1], c[1])));
    const maxY = Math.min(this.height - 1, Math.ceil(Math.max(a[1], b[1], c[1])));
    if (minX > maxX || minY > maxY) return;

    const area = (b[0] - a[0]) * (c[1] - a[1]) - (c[0] - a[0]) * (b[1] - a[1]);
    if (Math.abs(area) < 1e-9) return;
    const inv = 1 / area;

    for (let y = minY; y <= maxY; y += 1) {
      const py = y + 0.5;
      for (let x = minX; x <= maxX; x += 1) {
        const px = x + 0.5;
        const w0 = ((b[0] - px) * (c[1] - py) - (c[0] - px) * (b[1] - py)) * inv;
        const w1 = ((c[0] - px) * (a[1] - py) - (a[0] - px) * (c[1] - py)) * inv;
        const w2 = 1 - w0 - w1;
        if (w0 < 0 || w1 < 0 || w2 < 0) continue;
        const z = a[2] * w0 + b[2] * w1 + c[2] * w2;
        const idx = y * this.width + x;
        if (z >= this.depth[idx]) continue;
        this.depth[idx] = z;
        const i = idx * 4;
        this.rgba[i] = color[0];
        this.rgba[i + 1] = color[1];
        this.rgba[i + 2] = color[2];
        this.rgba[i + 3] = 255;
      }
    }
  }

  toPNG() {
    return encodePNG(this.width, this.height, this.rgba);
  }
}

/** Orthographic camera helper: world -> screen, with a depth in metres. */
export function makeProjector({ width, height, view = 'side', center = [0, 0.6, 0], scale = 190 }) {
  // side: look along +X  (character faces +Z, so we see the pose in profile)
  // front: look along -Z (camera in front, character facing us)
  // top:   look down -Y
  return (x, y, z) => {
    let u;
    let v;
    let d;
    if (view === 'side') {
      u = -(z - center[2]);
      v = y - center[1];
      d = x - center[0];
    } else if (view === 'front') {
      u = x - center[0];
      v = y - center[1];
      d = center[2] - z;
    } else {
      u = x - center[0];
      v = z - center[2];
      d = center[1] - y;
    }
    return [width / 2 + u * scale, height / 2 - v * scale, d];
  };
}

/** Convenience: render one skinned mesh + floor line into a canvas. */
export function drawFloor(canvas, project, zWorld = 0) {
  const a = project(-30, 0, zWorld);
  const b = project(30, 0, zWorld);
  const y = Math.round(a[1]);
  canvas.floorY(y, 1e3);
  void b;
}
