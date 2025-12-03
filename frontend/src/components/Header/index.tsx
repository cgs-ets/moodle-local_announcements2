import { Link } from "react-router-dom";
import { Avatar, Menu, UnstyledButton, Group, Text, Box, Button, Anchor, ActionIcon, Modal, Pill, Loader, Drawer } from '@mantine/core';
import { IconHome2, IconLogout, IconPlus, IconSearch, IconMenu, IconExternalLink } from '@tabler/icons-react';
import { useInterval } from "@mantine/hooks";
import { useEffect, useState } from "react";
import { fetchData, getConfig } from "../../utils";

export function Header() {

  const [menuOpened, setMenuOpened] = useState(false);

  const checkAuthStatus = async () => {
    const response = await fetchData({
      query: {
        methodname: 'local_activities-check_login',
      }
    })
    if (response.error && (response.exception?.errorcode === 'requireloginerror' || response.errorcode === 'requireloginerror')) {
      window.location.replace(getConfig().loginUrl)
    }
  }
  const interval = useInterval(() => checkAuthStatus(), 30000); // 30 seconds.
  useEffect(() => {
    interval.start();
    return interval.stop;
  }, []);




  return (
  <>
    <Box bg={getConfig().headerbg} className="fixed w-full left-0 top-0 z-50">
      <div className="px-6">
        <Group h={54} justify="space-between">

          <Group gap="md">
            <Link to="/" style={{ textDecoration: 'none' }}>
              <Text className="text-lg font-semibold" c={getConfig().headerfg}>{getConfig().toolname}</Text>
            </Link>
          </Group>


          <div className="flex items-center gap-4">

           

            <ActionIcon
              variant="transparent"
              color="white"
              className="mr-2 md:hidden"
              onClick={() => setMenuOpened(true)}
            >
              <IconMenu size={20} />
            </ActionIcon>


            <div className="items-center gap-4 hidden md:flex">
              <Anchor className="text-gray-200 hover:no-underline mr-4 text-md font-normal" href="/">{getConfig().sitename}</Anchor>
              <Anchor href="/local/announcements2" className="text-white hover:no-underline mr-4 text-md font-semibold">Announcements</Anchor>
              { getConfig().roles?.includes('staff') &&
                  <Button component={Link} to={"/new"} size="compact-md" radius="lg" color="blue" leftSection={<IconPlus size={20} />}>Create new</Button> 
              }
            </div>

            <Menu position="bottom-end" width={200} shadow="md">
              <Menu.Target>
                <UnstyledButton> 
                  <Group>
                    <Avatar size="sm" radius="xl" src={'/local/announcements2/avatar.php?username=' + getConfig().user.un} />
                  </Group>
                </UnstyledButton>
              </Menu.Target>
              <Menu.Dropdown>
                <Menu.Item leftSection={<IconHome2 size={14} />} onMouseDown={() => window.location.replace('/')}>{getConfig().sitename}</Menu.Item>
                <Menu.Item leftSection={<IconLogout size={14} />} onMouseDown={() => window.location.replace(getConfig().logoutUrl)}>Logout</Menu.Item>
              </Menu.Dropdown>
            </Menu>

          </div>
          
        </Group>
      </div>




      <Drawer position="right" opened={menuOpened} onClose={() => setMenuOpened(false)}>
        <div className="flex flex-col gap-4">
          <Anchor className="hover:no-underline mr-4 text-md font-normal flex items-center gap-1" href="/">{getConfig().sitename} <IconExternalLink size={13} /></Anchor>
          { getConfig().roles?.includes('staff') 
            ? <Button component={Link} to={"/announcement/new"} size="md" radius="lg" color="blue" leftSection={<IconPlus size={20} />}>Post announcement</Button> 
            : null
          }
        </div>
      </Drawer>

          
      
    </Box>
    <div className="h-[54px]"></div>
  </>
  );
}