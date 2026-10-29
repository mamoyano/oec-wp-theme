PDF.js 4.10.38 (pdfjs-dist, build "legacy": anda también en Safari/iOS viejos),
de Mozilla, licencia Apache 2.0 (LICENSE). Lo usa el visor [oec-pdf]
(inc/pdf-viewer.php, assets/js/pdf-viewer.js) y se baja recién al tocar "Ver PDF".

- pdf.min.js / pdf.worker.min.js = legacy/build/pdf.min.mjs y pdf.worker.min.mjs,
  renombrados a .js para que el servidor los mande como JavaScript (un .mjs
  puede salir como application/octet-stream y el navegador no lo ejecuta).
- standard_fonts/ = fuentes para los PDF que no traen las suyas incrustadas.
