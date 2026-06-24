import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getPage } from "@/lib/api";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const page = await getPage(slug);
  if (!page) return { title: "Page not found" };
  return { title: page.title };
}

export default async function StaticPage({ params }: Props) {
  const { slug } = await params;
  const page = await getPage(slug);

  if (!page) notFound();

  return (
    <article>
      <Link href="/posts" className="text-sm text-blue-600 hover:underline mb-6 inline-block">
        ← Blog
      </Link>

      <h1 className="text-3xl font-bold mb-8">{page.title}</h1>

      {page.published_at && (
        <time
          dateTime={page.published_at}
          className="text-sm text-gray-500 mb-6 block"
        >
          Updated{" "}
          {new Date(page.updated_at ?? page.published_at).toLocaleDateString("en-US", {
            year: "numeric",
            month: "long",
            day: "numeric",
          })}
        </time>
      )}

      <div
        className="prose prose-gray max-w-none"
        dangerouslySetInnerHTML={{ __html: page.content }}
      />
    </article>
  );
}
