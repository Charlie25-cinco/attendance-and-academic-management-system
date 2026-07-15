# AGENTS.md

## Workflow

Follow these phases for codebase work:

1. Plan: inspect the relevant files and propose the work.
2. Build: make approved changes only after the user accepts the plan.
3. Explain: summarize what changed, how it was checked, and any remaining risks.

Use "Accept or Reject" before moving from Plan to Build, and before making code or documentation edits.

## Project Expectations

- Keep changes small and focused.
- Update `README.md` and `AGENTS.md` when project behavior, setup, security posture, or workflow changes.
- Prefer existing project patterns over new abstractions.
- Do not commit local secrets, generated files, `vendor/`, `storage/`, or runtime uploads.
- Keep the project GitHub-ready by maintaining `.gitignore`, setup docs, and clear validation commands.
- Treat `deped/` as the source of truth for SF1, SF2, and ECR templates unless the user explicitly replaces a template.
- Runtime web pages should bootstrap through `functions/bootstrap.php`.

## Validation

Run focused checks after edits:

```sh
composer run lint
composer run test
```

If a check cannot be run, explain why in the final response.

For local manual testing, `composer run serve` starts the PHP development server at `http://localhost:5000/`.

## Security Notes

- Production must set `APP_ENV=production`.
- Production must provide strong `API_AUTH_SECRET` and `API_SYNC_SECRET` values.
- Production must set `API_ALLOWED_ORIGIN` to a trusted origin.
- API first-login password changes require the temporary token returned by the login endpoint.

## DepEd Forms

- SF1 follows `deped/SF1_Senior_High_School.xlsx`.
- SF2 follows `deped/SF2_Senior_High_School.xlsx`.
- ECR follows the three-term Strengthened SHS `.xlsx` template in `deped/ecr_template.xlsx` or `ECR_TEMPLATE_PATH`.
- SF1, SF2, and ECR imports/exports should only expose CSV and XLSX formats.
- Keep Admin SF1/SF2 export controls in Admin Reports and Teacher SF2 export controls in Teacher Reports.
- Teacher ECR preview, upload, import, and export routes live in `api/routes/12-ecr.php`; keep uploads `.xlsx` only and downloads `.csv`/`.xlsx`.
- Do not use `.xlsm` ECR workbooks as export templates.
- Do not reintroduce admin portal pages for ECR template management or academic year settings unless explicitly requested.
