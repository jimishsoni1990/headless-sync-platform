import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { getPost } from "@/lib/api";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const post = await getPost(slug);
  if (!post) return { title: "Post not found" };
  return { title: post.title, description: post.excerpt || undefined };
}

export default async function PostPage({ params }: Props) {
  const { slug } = await params;
  const post = await getPost(slug);

  if (!post) notFound();

  return (
    <article>
      <Link href="/posts" className="text-sm text-blue-600 hover:underline mb-6 inline-block">
        ← All posts
      </Link>

      <h1 className="text-3xl font-bold mb-3">{post.title}</h1>

      <div className="flex items-center gap-4 text-sm text-gray-500 mb-8">
        {post.published_at && (
          <time dateTime={post.published_at}>
            {new Date(post.published_at).toLocaleDateString("en-US", {
              year: "numeric",
              month: "long",
              day: "numeric",
            })}
          </time>
        )}
        {post.author && <span>By {post.author}</span>}
      </div>

      <div
        className="prose prose-gray max-w-none"
        dangerouslySetInnerHTML={{ __html: post.content }}
      />
    </article>
  );
}
