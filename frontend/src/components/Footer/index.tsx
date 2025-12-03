import { Container, Text } from "@mantine/core";
import { Fragment } from "react";

export function Footer() {
  return (
    <Fragment>
      <footer>
        <Container size="xl" py="md" className="flex gap-4 items-center">
          <Text className="hidden flex gap-1 items-center text-xs opacity-60 hover:opacity-100">Credits</Text>
        </Container>
      </footer>
    </Fragment>
  );
}