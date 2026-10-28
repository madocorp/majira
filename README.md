# MaJira

MaJira is a graphical Jira Cloud browser built with SPTK. Search issues with
filters or JQL, browse sprint boards, and work with ticket details. It keeps
local caches so previously loaded issues remain available between sessions.

## Requirements

- PHP 8.2 or newer on the command line, with FFI, XMLReader, mbstring, intl,
  cURL, and PCNTL enabled. GD is needed for some image formats.
- [SPTK](https://github.com/madocorp/SPTK), with SDL3 and SDL3_ttf available
  as described in its installation instructions. SDL3_ttf also needs FreeType
  and HarfBuzz; PNG images require `libpng16.so.16`.
- A graphical desktop session and a Jira Cloud account with an API token.

Check PHP with:

```sh
php --version
php -m | grep -Ei '^(FFI|xmlreader|mbstring|intl|curl|pcntl|gd)$'
```

Install missing PHP extensions and native libraries with your system's package
manager. Package names vary by system.

## Installation

MaJira is installed manually; it does not use Composer. This example installs
both projects for one user:

```sh
mkdir -p "$HOME/.local/share" "$HOME/.local/bin"
git clone https://github.com/madocorp/SPTK.git "$HOME/.local/share/SPTK"
git clone https://github.com/madocorp/majira.git "$HOME/.local/share/majira"
ln -sfn "$HOME/.local/share/SPTK" "$HOME/.local/share/majira/SPTK"
ln -s "$HOME/.local/share/majira/majira.php" "$HOME/.local/bin/majira"
```

MaJira loads SPTK from the directory or symlink named `SPTK` beside
`majira.php`. The clone includes a relative link for a sibling SPTK checkout;
the command above replaces it with an absolute link. Adjust the paths if you
install either project elsewhere. Ensure `~/.local/bin` is in your `PATH`, or
run `majira.php` by its full path. The entry point is executable in the
repository; restore that permission with `chmod +x majira.php` if needed.

## Running

```sh
majira
majira AP-1234
majira https://example.atlassian.net/browse/AP-1234
```

A ticket key or a ticket URL of the configured Jira site opens that ticket.
Only one MaJira runs at a time: when it is already running, a new start sends
its argument to it, brings its window to the front, and exits. This lets you
set MaJira as the handler for Jira links in your desktop.

On first use, open **Settings** with **F5**. Enter the HTTPS Jira site URL,
email address, and API token, then press **C** to verify and save the
connection. The token loads into a masked input on later visits; edit it to
replace the saved token. Project choices load after connecting.

Use **F1–F5** to switch between List, Filters, Board, Ticket, and Settings.
Arrow keys move between tiles. **Return** opens or activates a selected tile;
**Esc** usually leaves it. Press **?** on a selected tile for help. Persistent
status messages and errors are dismissed with **Return** or **Esc**.

### List and Filters

**List** (**F1**) shows issue search results. Edit the JQL line to run a
query, or press **F** to open the sidebar with saved filters and Last JQL.
**Return** applies the highlighted sidebar filter; **Esc** closes the sidebar.
**Return** on an issue opens its ticket. **R** refreshes the first page; **M**
or **F6** loads more results when available.

**Filters** (**F2**) provides project, board, sprint, search, date, status,
type, priority, and assignee choices. Select **Any project** to search across
projects. Confirming a project loads its relevant boards and choices;
confirming a board loads its sprints. Choices are cached, including empty
results. Builder changes save as you make them; **A** applies the filter.

Saved filters appear on the left. **N** creates one, **D** deletes the selected
one, and **Shift+Up/Down** changes their order. **C** clears the current
filter's values, while **R** clears cached filter choices. **J** switches a
filter to editable JQL. Create a new filter to use the builder again. Date
filters accept independent From and To values; **Return** on an empty date
slot opens a calendar, and **Delete** removes an active date.

### Board

**Board** (**F3**) has its own project, board, and sprint selectors. Choose a
sprint to load its issue cards; **R** refreshes them. **Return** on a card opens
the ticket. **Left/Right** moves between status columns, and **Up/Down** moves
between cards. **PageUp/PageDown** moves through a column; **Home/End** moves
across columns. The status bar shows the selected column among all statuses.

Press **F** for the assignee filter. Choose **0** for everybody or **1–5** for
the listed people; press **F** again for the next five, or **Esc** to close it.
Board results are cached by board and sprint and restored at startup.

### Ticket

**Ticket** (**F4**) shows the title, description, comments, attachments,
related tickets, and configured properties. **R** refreshes the ticket,
**C** opens New Ticket, and **H** opens recent ticket history. In History,
**Return** opens the highlighted ticket and **Esc** restores the previous one.

**Return** edits the title; **Return** or **Esc** saves it. The description
wraps by word and uses Markdown highlighting. **Ctrl+Return** saves a
description edit; **Esc** cancels it. Comments are read-only until opened:
press **Return** to browse, move the cursor into a comment, then press
**Return** to edit your own comment. **Insert** adds a comment and **Delete**
asks before removing one you own. **Ctrl+Return** saves a comment edit;
**Esc** cancels it.

Embedded attachments show as `<@file.txt>` lines in comments and the
description. To embed one, write `<@1>` (its row in the attachment table) or
`<@file.txt>` on its own line; images show as images, other files as cards.
In the comment reader, **L** or **Ctrl+L** opens the link on the cursor's line;
in the description, use **Ctrl+L**. An attachment opens in the preview, a
`<#KEY>` or a Jira browse URL opens its ticket in majira, and any other URL
opens in the default browser. With several links on a line, the one under the
cursor wins, otherwise the first.
When a file has no known name yet, opening Description or Comments asks Jira
for attachment ids once and caches them.

**Return** on an editable property opens an editor in the same tile. Text,
number, and issue-key values (Parent, Epic Link, Parent Link) use an input;
an empty key removes the link. Choices and users use a searchable list, and due
dates use a calendar. **Return** saves; **Esc** cancels. Sprint lists the
board's open sprints and Backlog; the board's estimation field, such as Story
Points, can be set even when Jira's edit screen omits it. Both use the Board
screen board when it belongs to the ticket's project, then the List board, then
the project's first board. Jira-managed Created and Updated fields cannot be
edited. **Return** on a related issue opens it.

**Insert** on the attachment table replaces it with a file browser: **Return**
opens a directory or uploads the file, **Esc** restores the table. **Delete**
on an attachment asks before removing it; Jira decides whether you may delete
it. **Return** on an attachment opens a full-window preview. Images support SPTK
zoom and pan (**+**, **-**, arrows, and **=** to fit). Text files open read-only;
CSV and TSV files open as tables. **Space** and **Backspace** move to the next
and previous attachment. **Esc** or **Return** closes the preview. Unsupported
formats, including legacy XLS, show an error.

### Settings

**Settings** (**F5**) has **C** to verify and save the connection, **F** to
configure fields, **R** to clear Jira caches, and **Q** to quit. The field
selector controls both List columns and Ticket property cards in the same
order. Selected fields appear above available fields. **Space** adds a field
at the end of the selected group or moves a deselected field just below it;
**Shift+Up/Down** reorders fields within a group. **Return** saves the list,
while **Esc** discards changes. Both return focus to the Fields button.
Key and Summary can appear in List, but Ticket uses its existing key heading
and title input instead of duplicate property cards.

MaJira keeps settings, the API token, and cached Jira data in SPTK's private
application data directory, normally `~/.majira`.

## Tests

Run the standalone checks from the MaJira checkout:

```sh
for test in Tests/*.php; do php "$test" || exit 1; done
```

## License

MaJira is released under the [Unlicense](UNLICENSE).
