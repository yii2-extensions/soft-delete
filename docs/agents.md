# AI assistance

Use `yii2-soft-delete` to help configure Yii ActiveRecord deletion markers, query scopes, restoration, and foreign-key
fallback. Its canonical source is [SKILL.md](../skills/yii2-soft-delete/SKILL.md).

## Install in an application

Follow the [one-time Composer setup](https://yii2-framework.github.io/docs/project/ai-agents/#composer-installation) to
enable `llm/skills`. Explicitly trust `yii2-extensions/soft-delete` in `dependencies.composer.trusted` in `skills.json`.
With `auto-sync` enabled and a package version that contains the bundle, run:

```bash
composer install
composer skills:show
```

With auto-sync disabled, run `composer skills:update` after installing or updating dependencies. To register an
unpublished bundle from a local checkout instead, run:

```bash
composer skills:add /absolute/path/to/soft-delete --skill=yii2-soft-delete
```

### Optional npx installation

List and install from the checkout:

```bash
npx skills@1.7.2 add /absolute/path/to/soft-delete --list
npx skills@1.7.2 add /absolute/path/to/soft-delete --skill yii2-soft-delete
```

## Verify discovery and use

Start a fresh client session in the application root, confirm that it lists the skill, and invoke it with a small
request. For example, in Codex:

```text
$yii2-soft-delete
Add an archive and restore workflow for an existing ActiveRecord model. Keep ordinary delete unchanged and verify
active, archived, and restored rows on a disposable database.
```

Discovering a bundle, loading it for a task, and passing database checks are separate. Use the installed package's
revision when choosing documentation. If a checkout lacks the skill or matching guides, inspect its code and PHPDoc
rather than substituting another package's API.

[Back to documentation](../README.md#documentation)
