# 04 - Optional location and future GIS intake

## Purpose and prerequisites

Mention a Baguio barangay in your question or use the optional browser-only location tools. Neither feature assesses hazards. Follow [getting started](01-getting-started.md#verify-the-browser-bridge) and open the Laravel website. Browser geolocation requires a secure context: use localhost on the laptop or trusted HTTPS on your phone. Internet access is optional unless you choose to load the reference tiles.

## Try and verify the current workflow

1. Open Chat and submit a preparedness question without selecting any location. Expect a normal chat result and no geolocation prompt. In browser DevTools, the Network list should have no `tile.openstreetmap.org` requests.
2. Ask `How do I prepare for a landslide in Irisan?`. The pinned roster can identify a place mention as local context, not determine local safety. Expect SANDIG to say it cannot verify local conditions. The UI has no barangay dropdown and does not save a selected barangay across messages.
3. Open **Location tools**, select **Check my device location (local only)** and deny permission. Expect an explanation; chat still works with no location. If you permit a new check, expect a one-time accuracy reading when the device supplies one. It must not assign a barangay. Select **Clear device location** when finished.
4. Choose **Load Baguio City reference map (OpenStreetMap)**. Expect an attributed reference map if tiles are reachable. A device point, if visible, is an approximate location overlay, not a hazard or jurisdiction marker; no tile requests should change merely because a device reading is obtained. **Hide map** removes the reference map. Compare the chat POST payload before and after a reading: no coordinates or accuracy fields may be present.
5. From the repo root, run `.\.venv\Scripts\python.exe -m pytest backend/tests/test_api.py -q`. The location tests check authentication, roster provenance, unknown IDs, unchanged general retrieval, local abstention, and catalog failure. The roster in `backend/sandig/baguio_barangays.tsv` is loaded at FastAPI startup; restart FastAPI after a reviewed snapshot update.

## Common errors and troubleshooting

- **A named barangay is not recognized:** check the spelling against the pinned TSV, confirm that FastAPI can load the file, and restart FastAPI after a reviewed roster update. Recognition is only a reason to abstain from local claims, not a hazard assessment.
- **Permission denied, location inaccurate, or timeout:** mention a place in a question; don't infer a barangay by zoom level, marker, or accuracy radius. Reset browser permission only if you want to retry the optional device check.
- **Reference tile error:** the map displays a status when a tile fails. Check the browser Network panel only after opting in; hide and reload the map to retry. Typed place names do not depend on the map. Do not bulk download or prefetch OSM tiles for offline use.

## Phase 6 intake handoff

Keep the originals and working exports in a private folder outside the repository. Share the folder path and name the first layer to validate. One complete, approved layer is enough to start; mark the others pending. Include a manifest or equivalent metadata that ties each file to its source edition and cites the written permission or license. State whether academic analysis, derived barangay summaries, and eventual public map display are each permitted; do not assume one grants the others.

Open the export in QGIS and confirm its CRS and alignment against the chosen reference. Compare sample categories to the original legend and include checks for unmapped or NoData areas and boundary cases. Provide the validated GeoTIFF and, if available, a classified raster or reviewed barangay-level results with the method and boundary version. A display-only GeoTIFF is acceptable for intake review, but not as evidence of a barangay classification. If the boundary crosswalk is unavailable or a code cannot be reconciled, leave the affected barangays unassessed.

### Intake checklist (integration not implemented)

- [ ] Provide reuse permission, publisher, edition and assessment dates, original legends and class definitions for all five map products. Distinguish **hazard**, **exposure**, **physical/environmental vulnerability**, and **risk**; do not synthesize a combined score.
- [ ] In QGIS, georeference the JPGs externally; record CRS, transform, control points and residuals. Check alignment against authoritative reference data. A display GeoTIFF is not automatically a classified raster. Preserve NoData and evaluate anti-aliasing, map labels and grid artifacts; do not interpolate category codes.
- [ ] Supply validated GeoTIFFs and any classified raster, GeoPackage, Shapefile (with its sidecar files) or GeoJSON boundaries, with a versioned attribute dictionary. For polygons, validate geometry, CRS and boundary vintage; join to reviewed PSGC code crosswalks, never solely to names. Keep unclassified, mixed and edge cases explicit.
- [ ] Review the method, coverage, exceptions, and sample results with the data owner. During Phase 6, agree on a typed geographic evidence contract that states provenance and limitations before adding spatial processing or displaying hazard results. Keep manual barangay selection usable without device geolocation and leave missing or unapproved results unavailable.

## Completion checklist

- [ ] Chat works without location and abstains on unverified named places.
- [ ] Device permission is user-initiated, denied permission is recoverable, and no precise coordinates are sent or stored.
- [ ] Tiles are opt-in and visibly attributed; the map does not claim authoritative coverage or safety.
- [ ] Current/local questions still abstain without verified evidence.

Official references: [PSA Baguio roster](https://psa.gov.ph/classification/psgc/barangays/1430300000), [OSMF tile policy](https://operations.osmfoundation.org/policies/tiles/), [Leaflet reference](https://leafletjs.com/reference.html), and [QGIS georeferencer documentation](https://docs.qgis.org/latest/en/docs/user_manual/working_with_raster/georeferencer.html).
