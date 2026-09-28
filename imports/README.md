# imports/

Coloca aquí los archivos grandes de Item Master (`.xlsx`, `.csv`, `.txt`).

Aparecerán en `item_master.php`, en la sección **"Archivos grandes: cargar desde la
carpeta del servidor"**. Esa ruta no pasa por el navegador, así que no le aplican
`upload_max_filesize` ni `post_max_size`: sirve para archivos de cualquier tamaño.
