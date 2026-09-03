# Project Process

## Issue & Progress Tracking

All work is tracked on the Hexalabs Roadmap board:
**https://github.com/users/FyWolf/projects/1**

### When starting a task
Create a GitHub issue in this repo and move it to "In Progress":
```bash
gh issue create --title "..." --body "..."
gh project item-add 1 --owner FyWolf --url <issue-url>
```

### When finishing a task
Move the issue to "In Review" (if a PR is open) or "Done".

### New ideas surfaced during work
Create an issue and leave it in "Backlog".

### Status field option IDs (for `gh project item-edit`)
- Backlog: `3c668a4c`
- Todo: `bc130aca`
- In Progress: `99f84552`
- In Review: `db7d5fc6`
- Done: `8ec320c3`

Project node ID: `PVT_kwHOALhxN84BgJaf`
Status field ID: `PVTSSF_lAHOALhxN84BgJafzhaXMWY`
