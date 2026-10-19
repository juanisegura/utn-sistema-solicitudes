# Equivalencia de hashes de commits

El 2026-10-19, a pedido de Juan, se reescribió el historial del repositorio para quitar de los
mensajes de commit las líneas de atribución automática de la herramienta de IA
(`Co-Authored-By` y `Claude-Session`). Autor, committer, fechas y contenido de cada commit no
cambiaron (el árbol final es idéntico), pero al cambiar el mensaje cambia el hash.

Los documentos vivos se actualizaron a los hashes nuevos. El **registro literal** de prompts
y respuestas (`registro/`) y las salidas archivadas de herramientas (`revisiones/*.txt`,
`../pruebas/evidencias/`) conservan los hashes originales a propósito, porque son
historia. Esta tabla permite traducirlos. Hay un respaldo del historial original fuera
del repositorio (bundle de git).

| Hash original | Hash actual |
|---------------|-------------|
| `fa60cf3` | `de37638` |
| `c27205f` | `649a0db` |
| `3486bd3` | `57caff6` |
| `52412c5` | `3afcfea` |
| `9636672` | `bf65f1c` |
| `7bb711d` | `e36056a` |
| `1d9440d` | `5efcb68` |
| `b23b76a` | `23ca0b6` |
| `c710de5` | `6005fcc` |
| `8f42aef` | `6dd95d3` |
| `6ea4d81` | `2e9e97a` |
| `af10282` | `25896c6` |
| `8e9da54` | `1280fef` |
| `a188ac5` | `6391034` |
| `3e8574d` | `0ec5a0a` |
| `b9826b6` | `2ded062` |
| `aaf3618` | `c850917` |
| `c2f0f69` | `e479309` |
| `f09af47` | `33f0646` |
| `4b12373` | `5594c5f` |
| `ff5a54f` | `015f0f8` |
| `a34a0d8` | `aa86133` |
| `2a31bdc` | `8c4a5d7` |
| `89f1c0c` | `44fd206` |
| `0f284c0` | `f0ab4c0` |
| `3b0bed6` | `523b622` |
