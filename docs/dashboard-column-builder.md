# Build a dashboard from table columns

Open a dashboard, choose **Visualize**, select a source table, then choose
**Column chart builder** or **Slicer**. Preview the result and click **Add to
dashboard**. The table is connected automatically. These controls also appear
under **Customize grid → Add widget**; use the widget's gear to configure it.
Drag and resize widgets in Customize mode, then click **Done** to save.

## Recreate the reference Dashboard's capabilities

Connect the table containing your calls. Columns may be managed fields or
custom columns populated through the app's imports or monday.com connection.
The builder uses those stored app records; it does not read an Excel workbook
or fetch a monday.com board directly.

| Widget | Category / filter column | Settings |
| --- | --- | --- |
| Year slicer | Call date | Filter values: **Year** |
| Month slicer | The same call date | Filter values: **Month**; 3 button columns |
| Model slicer | Model | Filter values: **Value**; multiple selections |
| Service Type slicer | Service Type | Filter values: **Value**; resize wide and choose up to 8 button columns |
| Calls per month | Call date | **Line**, **Count**, grouping **Year month** |
| Service Type by Model | Model | **Bar**, **Vertical**, **Count**, split into series: **Service Type** |
| Service Type totals | Service Type | **Bar**, **Horizontal**, **Count** |

If Year or Month is already a separate text/number column, use **Value** on
that column. Calendar grouping requires a date column. **Month** combines
the same calendar month across years; **Year month** keeps a chronological
timeline such as `2024-12`, `2025-01`.

## Filters and calculations

- Multiple choices within one slicer are combined with OR. Separate slicers
  are combined with AND. No selection means all values.
- Slicers affect charts and existing summary widgets from the **same source
  table**. They do not join unrelated tables. Their available choices reflect
  the other slicers and dashboard filters.
- Active filters appear above the grid. Clear one chip or use **Clear filters**.
  Slicer selections stay in the page URL and survive reloading; they are not
  saved as permanent filters in the dashboard layout.
- Blank values appear as **(Blank)**. Selected values remain visible with a
  zero count when other filters exclude them.
- **Count** counts records. Sum, average, minimum, and maximum require a numeric
  column from the same table. Missing numeric values are ignored by those
  calculations; they are not treated as zero.
- Chart data can be inspected in the expandable **View chart data** table.
  Long charts and slicer lists scroll inside their widgets. Regional access
  restrictions are enforced on the server; tables without a region cannot
  widen a regional manager's scope.

## Display limits

A chart can display up to 100 categories (default 30) and 20 series. A slicer
displays the first 500 values, searches those displayed values, and accepts up
to 100 selections. Limit warnings are shown; narrow other filters to see more
values. Category and series fields must belong to the same table. Custom
categorical values use the app's indexed display text (up to 255 characters).

The feature adds reusable builder capabilities. It does not import the reference
workbook's records or create a dashboard in the application's database.
