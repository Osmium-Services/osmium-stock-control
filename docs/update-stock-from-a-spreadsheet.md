---
title: Update stock from a spreadsheet
tags: stock, csv, import, export, excel, google sheets, bulk
---

You can update many products at once with a CSV file.

1. On the **Stock** page, press **Export CSV**.
2. Open the file in Excel or Google Sheets.
3. Change the **In stock**, **Reorder at**, **On order** and **Expected date** columns. Only these four are imported.
4. Save it as CSV.
5. Press **Import CSV**, choose the file and press **Import Stock**.

## The rules

- A number in **In stock** sets that product's stock. A product that was not tracked starts being tracked.
- Leave **In stock** blank to leave the product alone. Use **0** for none left.
- **Reorder at** works the same way: a number sets it, and blank leaves it as it is. A product needs an **In stock** figure before it can have one.

When it finishes you see how many were updated, unchanged, left alone and in error, with the errors listed.
