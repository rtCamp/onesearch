/**
 * Manual mock for `@floating-ui/react-dom`.
 */

const actual = jest.requireActual< typeof import('@floating-ui/react-dom') >(
	'@floating-ui/react-dom'
);

export const useFloating = actual.useFloating;
export const computePosition = actual.computePosition;
export const detectOverflow = actual.detectOverflow;
export const getOverflowAncestors = actual.getOverflowAncestors;
export const platform = actual.platform;
export const offset = actual.offset;
export const shift = actual.shift;
export const limitShift = actual.limitShift;
export const flip = actual.flip;
export const size = actual.size;
export const hide = actual.hide;
export const arrow = actual.arrow;
export const inline = actual.inline;
export const autoPlacement = actual.autoPlacement;

// Return a no-op cleanup function, matching autoUpdate()'s signature.
export const autoUpdate = () => () => {};
