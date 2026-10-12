# Functions — project helper classes

Helper (utility) classes that are **not** plugins live here, one class per
file, PSR-4 with the `Functions\` namespace:

```
Functions/Ui.php   →  Functions\Ui
```

The folder is autoloaded through `composer.json` (`"Functions\\": "Functions/"`)
in **both** the base skeleton and every bot built on it. Add the same entry
when you create a new bot and run `composer dump-autoload`.

Conventions:

- `Plugin/` is for classes implementing `PluginInterface` (plugins the
  PluginHandler discovers and dispatches to).
- `Functions/` is for everything else a plugin needs: keyboard/UI helpers,
  formatting utilities, small domain services. They are plain static/instance
  classes — no discovery, no middleware pipeline.
- Example of real-world usage: the payam bot keeps `Functions\Ui` (shared
  keyboards + helpers) and every plugin references it via `use Functions\Ui;`.
- Related: file-defined reply keyboards belong in `Button/` (see
  [buttons.md](buttons.md)) — helper classes should fetch them with
  `btn()` instead of building markup inline.
