---
paths:
  - 'resources/**'
---

# Resources

## Alpine CSP iframe players
The Alpine CSP evaluator rejects expressions attached to iframe elements, including :src. Create iframe players from the component JavaScript and remove them on close or teardown. Give players tabindex=0 so the shared modal focus loop includes them; verify opening, keyboard focus, and cleanup in a browser.
