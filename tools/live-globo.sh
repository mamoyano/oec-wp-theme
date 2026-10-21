#!/bin/sh
# Prepara el video de un docente para los globos del hero de LIVE
# (/especiales/live, inc/live.php → OEC_LIVE_GLOBOS).
#
# Uso:  tools/live-globo.sh <video> <número> [segundo de inicio]
#   <video>    cuadrado o no (se recorta al centro), con o sin audio
#   <número>   el siguiente libre en OEC_LIVE_GLOBOS (p. ej. 17)
#   [inicio]   desde qué segundo tomar los 8 s (por defecto 2)
#
# Deja assets/img/live/docentes/<número>.mp4 (8 s, 240×240, 24 fps, sin
# audio, ~45 KB) y <número>.webp (primer cuadro). Después, sumar en
# inc/live.php la línea  <número> => 'Nombre del docente',  y publicar.
# Necesita ffmpeg.
set -e
[ $# -ge 2 ] || { echo "Uso: $0 <video> <número> [segundo de inicio]"; exit 1; }
src="$1"; n="$2"; ss="${3:-2}"
dir="$(cd "$(dirname "$0")/.." && pwd)/assets/img/live/docentes"
vf="crop='min(iw,ih)':'min(iw,ih)',scale=240:240"
ffmpeg -v error -y -ss "$ss" -t 8 -i "$src" -an -vf "$vf,fps=24" -c:v libx264 -profile:v main -pix_fmt yuv420p -crf 32 -preset slow -movflags +faststart "$dir/$n.mp4"
ffmpeg -v error -y -ss "$ss" -i "$src" -frames:v 1 -vf "$vf" -c:v libwebp -quality 70 "$dir/$n.webp"
ls -l "$dir/$n.mp4" "$dir/$n.webp"
echo "Listo. Ahora sumá en inc/live.php (OEC_LIVE_GLOBOS):  $n => 'Nombre del docente',"
