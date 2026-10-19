# Merged columns and the Technical Service Analysis reference dashboard

## One column for workbook and monday rows

The page-156 report exposed a gap in the previous fix: hiding a source twin
left two column definitions, and saved visibility preferences could reveal both.
Managed core tables now expose one canonical column for each verified mapping.
The existing source cells stay in storage and remain available to the monday
mapping editor. No records, source values, saved mappings or accounts are deleted.

`MergedColumnValue` resolves a nonempty native value first, then an unambiguous
compatible source value. Explicit user clears remain blank through the edit log.
Account values retain precedence over conflicting board account values because
accounts are shared. Invalid legacy date strings do not become date cells.
File columns cannot become names or scalar fields. PMS and TSP read aliases do
not change the manual-only import/sync write rules.

The same resolution applies to the grid, column filters, sorting, exports,
column-chart builders, slicers and grouped summary datasets. Old saved chart
references to a merged custom column still resolve through the canonical field;
new field pickers offer only the canonical choice. Missing-PMS counts and their
drill-down use the same merged value as the displayed product column.

## Dashboard sheet applied to Technical Service Analysis

The supplied workbook's **Dashboard** sheet contains four slicers and three
charts. They now appear directly on the Technical Service Analysis core page:

- Year, Month, Model and Service Type, with multiple selections.
- Total Calls/Month: chronological monthly line chart.
- Service Type by Model: columns split into service-type series.
- Service Type in Total: horizontal bars.

The source is the app's Technical Reports table: one record counts as one call.
Call date defaults to Service start; Model uses the merged main Model field;
Service Type prefers a Service Type column, then Type of Request. **Chart fields**
lets the viewer choose available report fields. Selections are URL-bound. No
reference workbook records or cached chart numbers are imported.

Choices within a slicer combine with OR; separate slicers combine with AND.
All three charts and the existing report summary widgets share those filters.
Clear filters resets the selection. The completion-window control continues to
govern the existing completion trend. Existing shared summary layouts are retained.
Regional restrictions continue to constrain chart and slicer queries.

## Regression evidence

Before the change, both column-merging cases failed because `custom_1` remained
in the grid, and the core-dashboard test failed because Total Calls/Month was
absent. Tests cover mixed old/synced values, saved visibility, field edits and
clears, filters/export/chart agreement, saved chart aliases, the four slicers,
intersecting filters, configurable fields and invalid field identifiers.
