import Link from "next/link";
import type { ReactNode } from "react";

const variants = {
  primary:
    "border-wg-espresso bg-wg-espresso text-wg-ivory shadow-wg-button hover:bg-wg-sepia hover:text-wg-ink hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none",
  outline:
    "border-current bg-transparent text-wg-espresso hover:bg-wg-espresso hover:text-wg-ivory",
  light:
    "border-wg-ivory bg-wg-ivory text-wg-espresso shadow-wg-button hover:border-wg-sepia hover:bg-wg-sepia hover:text-wg-ink hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none",
};

export function Button({
  href,
  variant = "primary",
  children,
}: {
  href: string;
  variant?: keyof typeof variants;
  children: ReactNode;
}) {
  return (
    <Link
      href={href}
      className={`inline-block rounded-wg border-2 px-5 py-3 font-headline text-sm font-bold uppercase tracking-wider no-underline transition-all ${variants[variant]}`}
    >
      {children}
    </Link>
  );
}
