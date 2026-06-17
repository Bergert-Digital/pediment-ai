export type ChatImage = { media_type: string; data: string };

const ACCEPTED = ["image/png", "image/jpeg", "image/gif", "image/webp"];
const MAX_EDGE = 1568;

export function isAccepted(type: string): boolean {
  return ACCEPTED.includes(type);
}

/** Splits "data:image/png;base64,AAAA" into { media_type, data } (no data: prefix). */
export function splitDataUri(dataUri: string): ChatImage {
  const match = /^data:([^;]+);base64,(.*)$/.exec(dataUri);
  if (!match) throw new Error("Not a base64 data URI");
  return { media_type: match[1], data: match[2] };
}

/** Pure filter+cap step, separated from canvas work so it is unit-testable. */
export function selectFiles(
  files: File[],
  room: number,
): { accepted: File[]; rejected: boolean } {
  const valid = files.filter((f) => isAccepted(f.type));
  const accepted = valid.slice(0, Math.max(0, room));
  const rejected =
    valid.length < files.length || accepted.length < valid.length;
  return { accepted, rejected };
}

/** Validate, cap, and downscale a batch of files into base64 ChatImages. */
export async function prepareImages(
  files: File[],
  room: number,
): Promise<{ images: ChatImage[]; rejected: boolean }> {
  const { accepted, rejected: selectionRejected } = selectFiles(files, room);
  let rejected = selectionRejected;
  const images: ChatImage[] = [];
  for (const file of accepted) {
    try {
      images.push(await downscale(file));
    } catch {
      rejected = true;
    }
  }
  return { images, rejected };
}

async function downscale(file: File): Promise<ChatImage> {
  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
  const w = Math.round(bitmap.width * scale);
  const h = Math.round(bitmap.height * scale);
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext("2d");
  if (!ctx) throw new Error("no 2d context");
  try {
    ctx.drawImage(bitmap, 0, 0, w, h);
    // PNG keeps transparency; GIF/WebP/JPEG are re-encoded to JPEG (canvas can't emit GIF/WebP reliably).
    const outType = file.type === "image/png" ? "image/png" : "image/jpeg";
    return splitDataUri(canvas.toDataURL(outType, 0.85));
  } finally {
    bitmap.close();
  }
}
