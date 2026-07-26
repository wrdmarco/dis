export interface InterceptableNavigationEvent extends Event {
  canIntercept: boolean;
  destination: {
    url: string;
  };
  downloadRequest: string | null;
  formData: FormData | null;
  hashChange: boolean;
  intercept: (options: {
    precommitHandler: () => Promise<void>;
  }) => void;
}

interface BrowserNavigationController {
  addEventListener: (
    type: 'navigate',
    listener: (event: InterceptableNavigationEvent) => void,
  ) => void;
  removeEventListener: (
    type: 'navigate',
    listener: (event: InterceptableNavigationEvent) => void,
  ) => void;
}

export function browserNavigationController(): BrowserNavigationController | null {
  return (window as Window & { navigation?: BrowserNavigationController }).navigation ?? null;
}
