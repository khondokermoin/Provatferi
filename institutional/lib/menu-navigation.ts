/**
 * Keyboard and placement rules of the small action menu (components/LeaderActions.tsx), as pure functions so they are
 * unit-tested (__tests__/leadership-share.test.mts) without a browser.
 */

/** The index to focus after `key` inside a menu of `count` items, or null when the key is not a navigation key. */
export function nextMenuIndex(key: string, current: number, count: number): number | null {
  if (count <= 0) return null;
  switch (key) {
    case "ArrowDown":
      return current < 0 ? 0 : (current + 1) % count;
    case "ArrowUp":
      return current < 0 ? count - 1 : (current - 1 + count) % count;
    case "Home":
      return 0;
    case "End":
      return count - 1;
    default:
      return null;
  }
}

export interface Box {
  top: number;
  left: number;
  right: number;
  bottom: number;
}

export interface Size {
  width: number;
  height: number;
}

/**
 * Where a fixed-position menu goes so that it stays inside the viewport. It hangs below the trigger with its right edge on
 * the trigger's right edge; when it would run off the left or right side it is pushed back inside (`edge` px from the
 * side); when there is no room below it opens above; when there is no room above either it is pinned to the viewport
 * (the menu itself scrolls, see `max-height` in globals.css). The result is always inside the viewport, or flush with its
 * top-left when the menu is larger than the viewport.
 */
export function placeMenu(trigger: Box, menu: Size, viewport: Size, gap = 6, edge = 8): { top: number; left: number } {
  const maxLeft = viewport.width - menu.width - edge;
  const left = Math.max(edge, Math.min(trigger.right - menu.width, maxLeft));

  let top = trigger.bottom + gap;
  if (top + menu.height > viewport.height - edge) {
    const above = trigger.top - gap - menu.height;
    top = above >= edge ? above : Math.max(edge, viewport.height - menu.height - edge);
  }
  return { top, left };
}
